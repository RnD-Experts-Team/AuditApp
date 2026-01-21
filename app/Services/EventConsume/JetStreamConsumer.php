<?php

namespace App\Services\EventConsume;

use App\Models\EventInbox;
use App\Services\Nats\NatsClientFactory;
use Basis\Nats\Client;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JetStreamConsumer
{
    /**
     * After this many failures for the same event_id, we ACK it and park it.
     * This guarantees "stop trying" for that event_id.
     */
    private const MAX_PROCESSING_ATTEMPTS = 5;

    /**
     * Prevent hot spinning when the consumer loop itself errors (NATS down, auth, etc).
     */
    private const ERROR_BACKOFF_MS = 1000;

    /**
     * Delay for retries when handler fails (prevents tight redelivery loop).
     */
    private const NAK_DELAY_SECONDS = 2;

    private ?Client $client = null;

    public function __construct(
        private readonly NatsClientFactory $factory,
        private readonly EventRouter $router,
    ) {}

    public function runForever(): void
    {
        $streams = (array) config('nats.streams', []);
        if (count($streams) === 0) {
            throw new Exception('No streams configured in nats.streams');
        }

        $batch     = (int) config('nats.pull.batch', 25);
        $timeoutMs = (int) config('nats.pull.timeout_ms', 2000);
        $sleepMs   = (int) config('nats.pull.sleep_ms', 250);

        $this->client = $this->factory->make();

        Log::info('JetStream consumer started', [
            'streams' => array_map(fn($s) => [
                'name' => $s['name'] ?? null,
                'durable' => $s['durable'] ?? null,
                'filter_subject' => $s['filter_subject'] ?? null,
            ], $streams),
            'batch' => $batch,
            'timeout_ms' => $timeoutMs,
            'sleep_ms' => $sleepMs,
            'max_processing_attempts' => self::MAX_PROCESSING_ATTEMPTS,
            'nak_delay_seconds' => self::NAK_DELAY_SECONDS,
        ]);

        while (true) {
            try {
                foreach ($streams as $cfg) {
                    $this->consumeStream($cfg, $batch, $timeoutMs);
                }
            } catch (Throwable $e) {
                Log::error('JetStream consumer outer loop error', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                usleep(self::ERROR_BACKOFF_MS * 1000);
            }

            usleep(max(1, $sleepMs) * 1000);
        }
    }

    /**
     * @param array{name:string,durable:string,filter_subject:string} $cfg
     */
    private function consumeStream(array $cfg, int $batch, int $timeoutMs): void
    {
        $streamName    = (string) ($cfg['name'] ?? '');
        $durable       = (string) ($cfg['durable'] ?? '');
        $filterSubject = (string) ($cfg['filter_subject'] ?? '>');

        if ($streamName === '' || $durable === '') {
            throw new Exception('Stream config requires name + durable');
        }

        if (!$this->client) {
            $this->client = $this->factory->make();
            Log::warning('JetStream consumer client was null; recreated client', [
                'stream' => $streamName,
                'durable' => $durable,
            ]);
        }

        try {
            $api    = $this->client->getApi();
            $stream = $api->getStream($streamName);

            $consumer = $this->ensureConsumer($streamName, $stream, $durable, $filterSubject);

            // basis-company/nats.php pull-mode pattern
            $queue = $consumer->getQueue();

            // Library expects timeout in seconds
            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
            $queue->setTimeout($timeoutSeconds);

            $messages = $queue->fetchAll($batch);

            if (empty($messages)) {
                Log::debug('JetStream fetchAll returned no messages', [
                    'stream' => $streamName,
                    'durable' => $durable,
                    'batch' => $batch,
                    'timeout_seconds' => $timeoutSeconds,
                ]);
                return;
            }

            Log::debug('JetStream fetchAll received messages', [
                'stream' => $streamName,
                'durable' => $durable,
                'count' => count($messages),
            ]);

            foreach ($messages as $msg) {
                if ($msg === null) {
                    Log::debug('JetStream fetchAll returned null entry (skipped)', [
                        'stream' => $streamName,
                        'durable' => $durable,
                    ]);
                    continue;
                }

                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            usleep(self::ERROR_BACKOFF_MS * 1000);
        }
    }

    private function ensureConsumer(string $streamName, $stream, string $durable, string $filterSubject)
    {
        $consumer = $stream->getConsumer($durable);

        try {
            $consumer->getConfiguration()->setSubjectFilter($filterSubject);
        } catch (Throwable $e) {
            Log::warning('Failed setting consumer subject filter in client (continuing)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if (method_exists($consumer, 'exists') && !$consumer->exists()) {
                Log::warning('JetStream consumer does not exist; creating', [
                    'stream' => $streamName,
                    'durable' => $durable,
                    'filter_subject' => $filterSubject,
                ]);

                $consumer->create();

                Log::info('JetStream consumer created', [
                    'stream' => $streamName,
                    'durable' => $durable,
                    'filter_subject' => $filterSubject,
                ]);
            } else {
                Log::debug('JetStream consumer ensured (existing)', [
                    'stream' => $streamName,
                    'durable' => $durable,
                    'filter_subject' => $filterSubject,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Failed ensuring/creating JetStream consumer', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $consumer;
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        // IMPORTANT: do not reject messages just because property names differ.
        // We only require that ack/nack exists and that we can resolve ack subject.
        if (!method_exists($msg, 'ack') && !method_exists($msg, 'nack') && !method_exists($msg, 'nak')) {
            Log::warning('Message object does not support ack/nack (skipped)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'msg_class' => is_object($msg) ? get_class($msg) : gettype($msg),
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
            ]);
            return;
        }

        $raw = $this->extractBody($msg);

        if ($raw === '') {
            // Empty payload: ACK and ignore (prevents poison loop)
            $this->ackSafe($msg, $streamName, $durable, 'empty_payload');
            Log::warning('Empty payload message ACKed (ignored)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
            ]);
            return;
        }

        $event = json_decode($raw, true);

        if (!is_array($event)) {
            $this->ackSafe($msg, $streamName, $durable, 'non_json_payload');
            Log::warning('Non-JSON message ACKed (ignored)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
                'raw_preview' => mb_substr($raw, 0, 200),
            ]);
            return;
        }

        $eventId = (string) ($event['id'] ?? '');
        $subject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source  = (string) ($event['source'] ?? '');

        if ($eventId === '' || $subject === '') {
            $this->ackSafe($msg, $streamName, $durable, 'missing_id_or_subject');
            Log::warning('Invalid event envelope ACKed (missing id/subject)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId !== '' ? $eventId : null,
                'subject' => $subject !== '' ? $subject : null,
                'raw_preview' => mb_substr($raw, 0, 200),
            ]);
            return;
        }

        Log::debug('Event received', [
            'stream' => $streamName,
            'consumer' => $durable,
            'event_id' => $eventId,
            'subject' => $subject,
        ]);

        DB::beginTransaction();

        try {
            $inbox = EventInbox::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (!$inbox) {
                $inbox = EventInbox::create([
                    'event_id' => $eventId,
                    'subject' => $subject,
                    'source' => $source ?: null,
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'payload' => $event,
                    'processed_at' => null,
                    'attempts' => 0,
                    'parked_at' => null,
                    'last_error' => null,
                ]);

                $inbox = EventInbox::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();
            }

            if ($inbox && $inbox->parked_at) {
                DB::commit();
                $this->ackSafe($msg, $streamName, $durable, 'already_parked');

                Log::warning('Event is parked - ACKed and skipped', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                    'attempts' => (int) $inbox->attempts,
                    'parked_at' => $inbox->parked_at?->toDateTimeString(),
                ]);
                return;
            }

            if ($inbox && $inbox->processed_at) {
                DB::commit();
                $this->ackSafe($msg, $streamName, $durable, 'already_processed');

                Log::debug('Event already processed - ACKed', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                ]);
                return;
            }

            $handlerClass = $this->router->resolve($subject);

            Log::debug('Resolved handler', [
                'event_id' => $eventId,
                'subject' => $subject,
                'handler' => $handlerClass,
            ]);

            /** @var EventHandlerInterface $handler */
            $handler = app($handlerClass);
            $handler->handle($event);

            $inbox->processed_at = now();
            $inbox->last_error = null;
            $inbox->save();

            DB::commit();
            $this->ackSafe($msg, $streamName, $durable, 'processed_ok');

            Log::info('Event processed successfully - ACKed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
            ]);
        } catch (Throwable $e) {
            try {
                $locked = EventInbox::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($locked) {
                    $locked->attempts = (int) $locked->attempts + 1;
                    $locked->last_error = $e->getMessage();

                    if ($locked->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                        $locked->parked_at = now();
                        $locked->save();

                        DB::commit();
                        $this->ackSafe($msg, $streamName, $durable, 'parked_max_attempts');

                        Log::error('Event parked after max attempts - ACKed (stop retrying)', [
                            'stream' => $streamName,
                            'consumer' => $durable,
                            'event_id' => $eventId,
                            'subject' => $subject,
                            'attempts' => (int) $locked->attempts,
                            'max_attempts' => self::MAX_PROCESSING_ATTEMPTS,
                            'error' => $e->getMessage(),
                        ]);
                        return;
                    }

                    $locked->save();

                    DB::commit();
                    $this->nackWithDelaySafe($msg, $streamName, $durable, self::NAK_DELAY_SECONDS, 'handler_failed_retry');

                    Log::error('Event failed - NAKed (will retry)', [
                        'stream' => $streamName,
                        'consumer' => $durable,
                        'event_id' => $eventId,
                        'subject' => $subject,
                        'attempts' => (int) $locked->attempts,
                        'max_attempts' => self::MAX_PROCESSING_ATTEMPTS,
                        'nak_delay_seconds' => self::NAK_DELAY_SECONDS,
                        'error' => $e->getMessage(),
                    ]);
                    return;
                }
            } catch (Throwable $inner) {
                DB::rollBack();
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NAK_DELAY_SECONDS, 'attempt_update_failed');

                Log::error('Event failed and attempts could not be updated - NAKed', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                    'original_error' => $e->getMessage(),
                    'attempts_update_error' => $inner->getMessage(),
                ]);
                return;
            }

            DB::rollBack();
            $this->nackWithDelaySafe($msg, $streamName, $durable, self::NAK_DELAY_SECONDS, 'fallback_nak');

            Log::error('Event processing failed - NAKed (fallback)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Defensive body extraction for basis-company/nats.php + potential variations.
     */
    private function extractBody($msg): string
    {
        try {
            // Variant A: $msg->payload is object with ->body
            if (is_object($msg) && property_exists($msg, 'payload') && is_object($msg->payload)) {
                if (property_exists($msg->payload, 'body') && is_string($msg->payload->body)) {
                    return $msg->payload->body;
                }

                // Variant B: ->data (some versions)
                if (property_exists($msg->payload, 'data') && is_string($msg->payload->data)) {
                    return $msg->payload->data;
                }

                // Variant C: payload string-cast
                $s = (string) $msg->payload;
                if ($s !== '') return $s;
            }

            // Variant D: some libs expose body directly
            if (is_object($msg) && property_exists($msg, 'body') && is_string($msg->body)) {
                return $msg->body;
            }

            // Variant E: getter
            if (is_object($msg) && method_exists($msg, 'getBody')) {
                $b = $msg->getBody();
                if (is_string($b)) return $b;
            }

            // Variant F: string cast
            if (is_object($msg) && method_exists($msg, '__toString')) {
                $s = (string) $msg;
                if ($s !== '') return $s;
            }
        } catch (Throwable $e) {
            // ignore
        }

        return '';
    }

    /**
     * Reply subject differs between versions: replyTo, reply_to, reply, replySubject...
     */
    private function getMsgReply($msg): ?string
    {
        try {
            if (!is_object($msg)) return null;

            foreach (['replyTo', 'reply_to', 'reply', 'replySubject', 'reply_subject'] as $k) {
                if (property_exists($msg, $k) && is_string($msg->{$k}) && $msg->{$k} !== '') {
                    return $msg->{$k};
                }
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    private function getMsgSubject($msg): ?string
    {
        try {
            if (is_object($msg) && property_exists($msg, 'subject') && is_string($msg->subject)) {
                return $msg->subject;
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    private function ackSafe($msg, string $streamName, string $durable, string $reason): void
    {
        try {
            if (method_exists($msg, 'ack')) {
                $msg->ack();
                Log::debug('ACK sent', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'reason' => $reason,
                    'subject' => $this->getMsgSubject($msg),
                    'reply' => $this->getMsgReply($msg),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('ACK failed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * basis-company/nats.php uses nack($delaySeconds).
     * Some older code uses nak(). We support both.
     */
    private function nackWithDelaySafe($msg, string $streamName, string $durable, int $delaySeconds, string $reason): void
    {
        try {
            if (method_exists($msg, 'nack')) {
                $msg->nack($delaySeconds);

                Log::debug('NAK sent', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'reason' => $reason,
                    'delay_seconds' => $delaySeconds,
                    'subject' => $this->getMsgSubject($msg),
                    'reply' => $this->getMsgReply($msg),
                ]);

                return;
            }

            if (method_exists($msg, 'nak')) {
                // No delay support on nak()
                $msg->nak();

                Log::debug('NAK (nak) sent', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'reason' => $reason,
                    'delay_seconds' => $delaySeconds,
                    'subject' => $this->getMsgSubject($msg),
                    'reply' => $this->getMsgReply($msg),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('NAK failed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
