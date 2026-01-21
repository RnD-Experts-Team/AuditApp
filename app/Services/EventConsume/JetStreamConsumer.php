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
     * After this many handler failures for the SAME event_id, we permanently stop retrying it:
     * - we mark it parked in event_inbox
     * - we ACK (or TERM) the message so JetStream will NOT redeliver forever
     */
    private const MAX_PROCESSING_ATTEMPTS = 5;

    /**
     * Prevent hot spinning when the consumer loop itself errors (NATS down, auth, etc).
     */
    private const ERROR_BACKOFF_MS = 1000;

    /**
     * Delay between retries when a handler fails (prevents tight redelivery loop).
     * Note: this is a client-side request; JetStream redelivery timing is also affected by ack_wait/backoff settings.
     */
    private const NACK_DELAY_SECONDS = 2;

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

        // Create ONE client and reuse it forever (avoid connection storms).
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
            'nack_delay_seconds' => self::NACK_DELAY_SECONDS,
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

            $consumer = $this->ensureConsumer($streamName, $stream, $durable, $filterSubject, $batch);

            // Queue interface (this is the library-supported pull way)
            $queue = $consumer->getQueue();

            // Library timeout is in seconds
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

    /**
     * IMPORTANT:
     * Even if the durable consumer already exists on the server (created by CLI),
     * the client-side Consumer object should be initialized via ->create() so the
     * queue/ack context is correctly set up.
     */
    private function ensureConsumer(string $streamName, $stream, string $durable, string $filterSubject, int $batch)
    {
        $consumer = $stream->getConsumer($durable);

        // Apply filter + batching on the client object (server config remains server-owned).
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
            if (method_exists($consumer, 'setBatching')) {
                $consumer->setBatching($batch);
            }
        } catch (Throwable $e) {
            Log::warning('Failed setting consumer batching in client (continuing)', [
                'stream' => $streamName,
                'durable' => $durable,
                'batch' => $batch,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // This is idempotent in this library’s intended usage (see README examples).
            $consumer->create();

            Log::debug('JetStream consumer initialized (create called)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'batch' => $batch,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed initializing JetStream consumer (create failed)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw $e;
        }

        return $consumer;
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        $raw = $this->extractBody($msg);

        Log::debug('Message received (raw)', [
            'stream' => $streamName,
            'consumer' => $durable,
            'msg_class' => is_object($msg) ? get_class($msg) : gettype($msg),
            'subject' => $this->getMsgSubject($msg),
            'reply' => $this->getMsgReply($msg),
            'payload_len' => strlen($raw),
        ]);

        if ($raw === '') {
            // If payload truly empty, ACK/TERM so it doesn't loop forever.
            $this->ackOrTermSafe($msg, $streamName, $durable, 'empty_payload');
            Log::warning('Empty payload message ACKed/TERMed (ignored)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
            ]);
            return;
        }

        $event = json_decode($raw, true);

        if (!is_array($event)) {
            $this->ackOrTermSafe($msg, $streamName, $durable, 'non_json_payload');
            Log::warning('Non-JSON message ACKed/TERMed (ignored)', [
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
            $this->ackOrTermSafe($msg, $streamName, $durable, 'missing_id_or_subject');
            Log::warning('Invalid event envelope ACKed/TERMed (missing id/subject)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId !== '' ? $eventId : null,
                'subject' => $subject !== '' ? $subject : null,
                'raw_preview' => mb_substr($raw, 0, 200),
            ]);
            return;
        }

        Log::debug('Event decoded', [
            'stream' => $streamName,
            'consumer' => $durable,
            'event_id' => $eventId,
            'subject' => $subject,
            'source' => $source ?: null,
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

            // If already parked => hard stop forever
            if ($inbox && $inbox->parked_at) {
                DB::commit();
                $this->ackOrTermSafe($msg, $streamName, $durable, 'already_parked');

                Log::warning('Event is parked - ACKed/TERMed and skipped', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                    'attempts' => (int) $inbox->attempts,
                    'parked_at' => $inbox->parked_at?->toDateTimeString(),
                ]);
                return;
            }

            // If already processed => idempotency
            if ($inbox && $inbox->processed_at) {
                DB::commit();
                $this->ackOrTermSafe($msg, $streamName, $durable, 'already_processed');

                Log::debug('Event already processed - ACKed/TERMed', [
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
            $this->ackOrTermSafe($msg, $streamName, $durable, 'processed_ok');

            Log::info('Event processed successfully - ACKed/TERMed', [
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

                    // If attempts exceeded => PARK and STOP retry forever
                    if ($locked->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                        $locked->parked_at = now();
                        $locked->save();

                        DB::commit();
                        $this->ackOrTermSafe($msg, $streamName, $durable, 'parked_max_attempts');

                        Log::error('Event parked after max attempts - ACKed/TERMed (stop retrying forever)', [
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
                    $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'handler_failed_retry');

                    Log::error('Event failed - NACKed (will retry)', [
                        'stream' => $streamName,
                        'consumer' => $durable,
                        'event_id' => $eventId,
                        'subject' => $subject,
                        'attempts' => (int) $locked->attempts,
                        'max_attempts' => self::MAX_PROCESSING_ATTEMPTS,
                        'nack_delay_seconds' => self::NACK_DELAY_SECONDS,
                        'error' => $e->getMessage(),
                    ]);
                    return;
                }
            } catch (Throwable $inner) {
                DB::rollBack();
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'attempt_update_failed');

                Log::error('Event failed and attempts could not be updated - NACKed', [
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
            $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'fallback_nack');

            Log::error('Event processing failed - NACKed (fallback)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ✅ Correct for basis-company/nats.php:
     * JetStream queue messages usually contain the actual body in $msg->payload (string).
     */
    private function extractBody($msg): string
    {
        try {
            // Most common for this library (see README examples):
            if (is_object($msg) && property_exists($msg, 'payload') && is_string($msg->payload)) {
                return $msg->payload;
            }

            // Some variations:
            if (is_object($msg) && property_exists($msg, 'body') && is_string($msg->body)) {
                return $msg->body;
            }

            if (is_object($msg) && method_exists($msg, 'getBody')) {
                $b = $msg->getBody();
                if (is_string($b)) return $b;
            }

            if (is_object($msg) && method_exists($msg, '__toString')) {
                $s = (string) $msg;
                if ($s !== '') return $s;
            }
        } catch (Throwable $e) {
            // ignore
        }

        return '';
    }

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
            if (is_object($msg) && property_exists($msg, 'subject') && is_string($msg->subject) && $msg->subject !== '') {
                return $msg->subject;
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    /**
     * ACK if possible; if ACK fails because ack context is missing,
     * try TERM (hard stop) if supported by the message type.
     */
    private function ackOrTermSafe($msg, string $streamName, string $durable, string $reason): void
    {
        // 1) Try ack()
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
                return;
            }
        } catch (Throwable $e) {
            Log::warning('ACK failed (will try TERM if available)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $this->getMsgReply($msg),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        // 2) Try term() as a hard stop
        try {
            if (method_exists($msg, 'term')) {
                $msg->term();
                Log::warning('TERM sent (hard stop)', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'reason' => $reason,
                    'subject' => $this->getMsgSubject($msg),
                    'reply' => $this->getMsgReply($msg),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('TERM failed', [
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
     * basis-company/nats.php supports $msg->nack($delaySeconds).
     * Some versions have nak() instead.
     */
    private function nackWithDelaySafe($msg, string $streamName, string $durable, int $delaySeconds, string $reason): void
    {
        try {
            if (method_exists($msg, 'nack')) {
                $msg->nack($delaySeconds);
                Log::debug('NACK sent', [
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
                $msg->nak(); // no delay support
                Log::debug('NAK sent (no delay)', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'reason' => $reason,
                    'delay_seconds' => $delaySeconds,
                    'subject' => $this->getMsgSubject($msg),
                    'reply' => $this->getMsgReply($msg),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('NACK/NAK failed', [
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
