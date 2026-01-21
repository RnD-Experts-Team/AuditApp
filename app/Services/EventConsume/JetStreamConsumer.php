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
    private const MAX_PROCESSING_ATTEMPTS = 5;
    private const ERROR_BACKOFF_MS = 1000;
    private const NACK_DELAY_SECONDS = 2;

    /**
     * Hard safety:
     * if message subject does NOT match the configured filter prefix, ignore it completely.
     * This prevents "handler.*" garbage from burning CPU/logs forever.
     */
    private const SUBJECT_ALLOW_PREFIXES = [
        'auth.v1.',
        // add more namespaces if you consume them here later
        // 'users.v1.',
    ];

    private ?Client $client = null;

    /**
     * Cache consumers we already initialized so we don't call create() every iteration.
     * @var array<string, bool>
     */
    private array $consumerInitialized = [];

    /**
     * Rate-limit noisy logs for garbage messages.
     */
    private int $lastGarbageLogAt = 0;

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
            'nack_delay_seconds' => self::NACK_DELAY_SECONDS,
            'subject_allow_prefixes' => self::SUBJECT_ALLOW_PREFIXES,
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

            $queue = $consumer->getQueue();

            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
            $queue->setTimeout($timeoutSeconds);

            $messages = $queue->fetchAll($batch);
            if (empty($messages)) {
                return;
            }

            foreach ($messages as $msg) {
                if ($msg === null) {
                    continue;
                }

                // 🔥 Hard filter BEFORE doing anything else
                $subject = $this->getMsgSubject($msg) ?? '';
                if (!$this->isAllowedSubject($subject)) {
                    $this->maybeLogGarbage($streamName, $durable, $msg, $subject);
                    // Do NOT ack: this is not a real JetStream ackable message anyway (reply=null),
                    // and acking it is what causes errors/log storms.
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

    private function ensureConsumer(string $streamName, $stream, string $durable, string $filterSubject, int $batch)
    {
        $consumer = $stream->getConsumer($durable);

        // Configure the client-side consumer object
        try {
            $consumer->getConfiguration()->setSubjectFilter($filterSubject);
        } catch (Throwable $e) {
            // ignore
        }

        try {
            if (method_exists($consumer, 'setBatching')) {
                $consumer->setBatching($batch);
            }
        } catch (Throwable $e) {
            // ignore
        }

        // ✅ Call create() only once per stream+durable
        $key = $streamName . '::' . $durable;
        if (!isset($this->consumerInitialized[$key])) {
            $consumer->create();
            $this->consumerInitialized[$key] = true;

            Log::debug('JetStream consumer initialized (create called once)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'batch' => $batch,
            ]);
        }

        return $consumer;
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        $raw = $this->extractBody($msg);

        // Minimal debug (you can keep this or remove it)
        Log::debug('Message received', [
            'stream' => $streamName,
            'consumer' => $durable,
            'subject' => $this->getMsgSubject($msg),
            'reply' => $this->getMsgReply($msg),
            'payload_len' => strlen($raw),
        ]);

        $event = json_decode($raw, true);
        if (!is_array($event)) {
            // Real JetStream msg but bad JSON: ack so it doesn't loop
            $this->ackSafe($msg, $streamName, $durable, 'non_json');
            return;
        }

        $eventId = (string) ($event['id'] ?? '');
        $subject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source  = (string) ($event['source'] ?? '');

        if ($eventId === '' || $subject === '') {
            $this->ackSafe($msg, $streamName, $durable, 'missing_id_or_subject');
            return;
        }

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
                return;
            }

            if ($inbox && $inbox->processed_at) {
                DB::commit();
                $this->ackSafe($msg, $streamName, $durable, 'already_processed');
                return;
            }

            $handlerClass = $this->router->resolve($subject);
            /** @var EventHandlerInterface $handler */
            $handler = app($handlerClass);

            $handler->handle($event);

            $inbox->processed_at = now();
            $inbox->last_error = null;
            $inbox->save();

            DB::commit();
            $this->ackSafe($msg, $streamName, $durable, 'processed_ok');

            Log::info('Event processed', [
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

                        Log::error('Event parked (stop retrying)', [
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

                    // IMPORTANT: Only log failures while still retrying
                    Log::warning('Event failed (will retry)', [
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
            } catch (Throwable $inner) {
                DB::rollBack();
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'attempt_update_failed');
                return;
            }

            DB::rollBack();
            $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'fallback_nack');
        }
    }

    private function extractBody($msg): string
    {
        try {
            if (is_object($msg) && property_exists($msg, 'payload') && is_string($msg->payload)) {
                return $msg->payload;
            }
        } catch (Throwable $e) {
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

    private function ackSafe($msg, string $streamName, string $durable, string $reason): void
    {
        try {
            if (method_exists($msg, 'ack')) {
                $msg->ack();
            }
        } catch (Throwable $e) {
            // If ack fails here, it is a real problem for real messages, so log it once.
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

    private function nackWithDelaySafe($msg, string $streamName, string $durable, int $delaySeconds, string $reason): void
    {
        try {
            if (method_exists($msg, 'nack')) {
                $msg->nack($delaySeconds);
                return;
            }

            if (method_exists($msg, 'nak')) {
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

    private function isAllowedSubject(string $subject): bool
    {
        if ($subject === '') return false;

        foreach (self::SUBJECT_ALLOW_PREFIXES as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log garbage messages at most once per 60 seconds (so your logs don't explode).
     */
    private function maybeLogGarbage(string $streamName, string $durable, $msg, string $subject): void
    {
        $now = time();
        if (($now - $this->lastGarbageLogAt) < 60) {
            return;
        }
        $this->lastGarbageLogAt = $now;

        Log::warning('Ignored non-domain message from queue (likely internal handler.* garbage)', [
            'stream' => $streamName,
            'consumer' => $durable,
            'subject' => $subject,
            'reply' => $this->getMsgReply($msg),
            'payload_len' => strlen($this->extractBody($msg)),
        ]);
    }
}
