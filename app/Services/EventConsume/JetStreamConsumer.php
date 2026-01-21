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
     * After this many failures for the same event_id, we ACK/TERM it and park it.
     * This guarantees "stop trying" for that event_id on the application side.
     */
    private const MAX_PROCESSING_ATTEMPTS = 5;

    /**
     * Prevent hot spinning when the consumer loop itself errors (NATS down, auth, etc).
     */
    private const ERROR_BACKOFF_MS = 1000;

    /**
     * Delay for retries when handler fails (prevents tight redelivery loop).
     * NOTE: This delay works only if the library supports nack($delaySeconds).
     */
    private const NACK_DELAY_SECONDS = 2;

    /**
     * Domain subjects prefix allowlist.
     * We ignore anything else (including the internal "handler.*" junk you saw).
     */
    private const SUBJECT_ALLOW_PREFIXES = [
        'auth.v1.',
    ];

    private ?Client $client = null;

    /**
     * Cache consumer objects per stream+durable so we don't "create called" every loop.
     * @var array<string, mixed>
     */
    private array $consumerCache = [];

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

        // IMPORTANT: create ONE client and reuse it forever.
        $this->client = $this->factory->make();


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
        }

        try {
            $consumer = $this->getOrInitConsumer($streamName, $durable, $filterSubject);

            // basis-company/nats.php pull-mode pattern:
            // - consumer->getQueue()->fetchAll($batch)
            $queue = $consumer->getQueue();

            // Library expects timeout in seconds
            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
            if (method_exists($queue, 'setTimeout')) {
                $queue->setTimeout($timeoutSeconds);
            }

            $messages = $queue->fetchAll($batch);

            if (empty($messages)) {
                return;
            }

            foreach ($messages as $msg) {
                if ($msg === null) {
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
     * Initializes and caches the JetStream consumer client-side object once.
     * NOTE: This does NOT create the consumer on the server; you already did via CLI.
     */
    private function getOrInitConsumer(string $streamName, string $durable, string $filterSubject)
    {
        $key = $streamName . '|' . $durable;

        if (isset($this->consumerCache[$key])) {
            return $this->consumerCache[$key];
        }

        $api    = $this->client->getApi();
        $stream = $api->getStream($streamName);

        $consumer = $stream->getConsumer($durable);

        // Try to set subject filter on client-side config (safe best effort).
        try {
            if (method_exists($consumer, 'getConfiguration')) {
                $cfg = $consumer->getConfiguration();
                if (is_object($cfg) && method_exists($cfg, 'setSubjectFilter')) {
                    $cfg->setSubjectFilter($filterSubject);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed setting consumer subject filter in client (continuing)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
            ]);
        }

        // Some versions require create() to initialize internals; call once only.
        try {
            if (method_exists($consumer, 'create')) {
                $consumer->create();
            }
        } catch (Throwable $e) {
            // If server consumer exists, create() may still be fine; if it throws, we still keep going.
            Log::debug('Consumer create() threw (continuing if server consumer exists)', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->consumerCache[$key] = $consumer;
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        $subject = $this->getMsgSubject($msg);
        $reply   = $this->getMsgReply($msg);

        // HARD RULE:
        // Real JetStream deliveries ALWAYS have a reply that starts with $JS.ACK.
        // Your "handler.*" junk has reply=null and should NOT be acked/termed/nacked.
        if (!$this->isJetStreamDelivery($reply)) {
            return;
        }

        // Domain allowlist: ignore anything not matching your domain prefixes
        if (!$this->isAllowedDomainSubject($subject)) {
            // ACK/TERM so it does not keep coming back to this consumer.
            $this->ackOrTermSafe($msg, $streamName, $durable, 'subject_not_allowed');
            return;
        }

        $raw = $this->extractBody($msg);

        if ($raw === '') {
            // For real JetStream delivery with empty payload: poison → ACK/TERM it once.
            $this->ackOrTermSafe($msg, $streamName, $durable, 'empty_payload');
            return;
        }

        $event = json_decode($raw, true);

        if (!is_array($event)) {
            $this->ackOrTermSafe($msg, $streamName, $durable, 'non_json_payload');
            return;
        }

        $eventId    = (string) ($event['id'] ?? '');
        $evtSubject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source     = (string) ($event['source'] ?? '');

        if ($eventId === '' || $evtSubject === '') {
            $this->ackOrTermSafe($msg, $streamName, $durable, 'missing_id_or_subject');
            return;
        }


        DB::beginTransaction();

        try {
            // Idempotency + attempts counter
            $inbox = EventInbox::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (!$inbox) {
                EventInbox::query()->create([
                    'event_id' => $eventId,
                    'subject' => $evtSubject,
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

            // If parked, never retry
            if ($inbox && $inbox->parked_at) {
                DB::commit();
                $this->ackOrTermSafe($msg, $streamName, $durable, 'already_parked');

                // Keep this warning (rare)
                Log::warning('Event is parked - ACKed/TERMed and skipped', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $evtSubject,
                    'attempts' => (int) $inbox->attempts,
                    'parked_at' => $inbox->parked_at?->toDateTimeString(),
                ]);
                return;
            }

            // If processed, ACK and exit
            if ($inbox && $inbox->processed_at) {
                DB::commit();
                $this->ackOrTermSafe($msg, $streamName, $durable, 'already_processed');
                return;
            }

            $handlerClass = $this->router->resolve($evtSubject);

            /** @var EventHandlerInterface $handler */
            $handler = app($handlerClass);
            $handler->handle($event);

            $inbox->processed_at = now();
            $inbox->last_error = null;
            $inbox->save();

            DB::commit();
            $this->ackOrTermSafe($msg, $streamName, $durable, 'processed_ok');
        } catch (Throwable $e) {
            // On handler failure: increment attempts and decide retry vs park
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
                        $this->ackOrTermSafe($msg, $streamName, $durable, 'parked_max_attempts');
                        return;
                    }

                    $locked->save();

                    DB::commit();
                    $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'handler_failed_retry');
                    return;
                }

                // If no locked row (shouldn't happen): rollback and retry
                DB::rollBack();
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'missing_inbox_row');
            } catch (Throwable $inner) {
                DB::rollBack();
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'attempt_update_failed');

                Log::error('Event failed and attempts could not be updated - NACKed', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $evtSubject,
                    'original_error' => $e->getMessage(),
                    'attempts_update_error' => $inner->getMessage(),
                ]);
            }
        }
    }

    private function isJetStreamDelivery(?string $reply): bool
    {
        return is_string($reply) && str_starts_with($reply, '$JS.ACK.');
    }

    private function isAllowedDomainSubject(?string $subject): bool
    {
        if (!is_string($subject) || $subject === '') return false;

        foreach (self::SUBJECT_ALLOW_PREFIXES as $prefix) {
            if ($prefix !== '' && str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Most common for Basis\Nats\Message\Msg is: public string $payload
     * Your previous extractor was missing this, which is why you saw payload_len=0.
     */
    private function extractBody($msg): string
    {
        try {
            if (!is_object($msg)) return '';

            if (property_exists($msg, 'payload') && is_string($msg->payload)) {
                return $msg->payload;
            }

            if (property_exists($msg, 'body') && is_string($msg->body)) {
                return $msg->body;
            }

            if (method_exists($msg, 'getBody')) {
                $b = $msg->getBody();
                if (is_string($b)) return $b;
            }

            if (method_exists($msg, '__toString')) {
                $s = (string) $msg;
                return $s !== '' ? $s : '';
            }
        } catch (Throwable $e) {
            // ignore
        }

        return '';
    }

    private function payloadLenSafe($msg): int
    {
        try {
            $raw = $this->extractBody($msg);
            return strlen($raw);
        } catch (Throwable $e) {
            return 0;
        }
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
            if (is_object($msg) && property_exists($msg, 'subject') && is_string($msg->subject)) {
                return $msg->subject;
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    /**
     * ACK sometimes throws in Basis if internal ack object cannot be built.
     * TERM (if available) is a safe fallback for poison messages.
     */
    private function ackOrTermSafe($msg, string $streamName, string $durable, string $reason): void
    {
        try {
            if (method_exists($msg, 'ack')) {
                $msg->ack();

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

        try {
            if (method_exists($msg, 'term')) {
                $msg->term();
            }
        } catch (Throwable $e) {
            Log::warning('TERM failed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
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

                return;
            }

            if (method_exists($msg, 'nak')) {
                // No delay support on nak()
                $msg->nak();
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
