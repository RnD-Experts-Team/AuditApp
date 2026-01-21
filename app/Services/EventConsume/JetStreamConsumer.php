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
     * After this many deliveries for the same message, we ACK it and park it in event_inbox with last_error.
     * This prevents infinite redelivery storms when a handler is broken or ordering is wrong.
     */
    private const MAX_PROCESSING_ATTEMPTS = 5;

    /**
     * If the loop is erroring hard, backoff a bit so we don’t hot-spin.
     */
    private const ERROR_BACKOFF_MS = 1000;

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

        // IMPORTANT: create ONE client and reuse it forever.
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
        ]);

        while (true) {
            try {
                foreach ($streams as $cfg) {
                    $this->consumeStream($cfg, $batch, $timeoutMs);
                }
            } catch (Throwable $e) {
                Log::error('JetStream consumer outer loop error', [
                    'error' => $e->getMessage(),
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
            // Safety: should never happen, but prevents null usage.
            $this->client = $this->factory->make();

            Log::warning('JetStream consumer client was null; recreated client', [
                'stream' => $streamName,
                'durable' => $durable,
            ]);
        }

        try {
            $api    = $this->client->getApi();
            $stream = $api->getStream($streamName);

            $consumer = $this->ensureConsumer($stream, $durable, $filterSubject);

            // NOTE: your Basis library version did not support Consumer::pull() earlier.
            // You said you fixed it now; keeping your call here as requested.
            $messages = $consumer->pull($batch, $timeoutMs);

            if (empty($messages)) {
                Log::debug('JetStream pull returned no messages', [
                    'stream' => $streamName,
                    'durable' => $durable,
                    'batch' => $batch,
                    'timeout_ms' => $timeoutMs,
                ]);
                return;
            }

            Log::debug('JetStream pull received messages', [
                'stream' => $streamName,
                'durable' => $durable,
                'count' => count($messages),
                'batch' => $batch,
                'timeout_ms' => $timeoutMs,
            ]);

            foreach ($messages as $msg) {
                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'batch' => $batch,
                'timeout_ms' => $timeoutMs,
                'error' => $e->getMessage(),
            ]);

            // Backoff slightly on stream errors to avoid hot-spin
            usleep(self::ERROR_BACKOFF_MS * 1000);
        }
    }

    private function ensureConsumer($stream, string $durable, string $filterSubject)
    {
        try {
            $c = $stream->getConsumer($durable);

            Log::debug('JetStream consumer ensured (existing)', [
                'durable' => $durable,
                'filter_subject' => $filterSubject,
            ]);

            return $c;
        } catch (Throwable $e) {
            Log::warning('JetStream consumer not found; creating consumer', [
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
            ]);

            // Fallback: create consumer if missing.
            // NOTE: Prefer creating via NATS CLI so you can control max_deliver/backoff cleanly.
            $c = $stream->createConsumer([
                'durable_name'      => $durable,
                'ack_policy'        => 'explicit',
                'deliver_policy'    => 'all',
                'filter_subject'    => $filterSubject,

                // IMPORTANT: don’t allow endless pending acks
                'max_ack_pending'   => 2000,

                // IMPORTANT: stop infinite redelivery
                'max_deliver'       => self::MAX_PROCESSING_ATTEMPTS,

                // Keep retry timing reasonable
                'ack_wait'          => 30, // seconds (library may accept seconds)

                // Pull tuning (server-side defaults if ignored by library)
                'max_waiting'       => 128,
            ]);

            Log::info('JetStream consumer created', [
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'max_ack_pending' => 2000,
                'max_deliver' => self::MAX_PROCESSING_ATTEMPTS,
                'ack_wait_seconds' => 30,
                'max_waiting' => 128,
            ]);

            return $c;
        }
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        // If NATS has already tried delivering this message too many times, ACK + park it.
        $deliveries = $this->getNumDelivered($msg);

        $raw = $this->extractBody($msg);
        $event = json_decode($raw, true);

        $eventId = is_array($event) ? (string) ($event['id'] ?? '') : '';
        $subject = is_array($event) ? (string) ($event['subject'] ?? $event['type'] ?? '') : '';

        Log::debug('JetStream message received', [
            'stream' => $streamName,
            'consumer' => $durable,
            'deliveries' => $deliveries,
            'event_id' => $eventId !== '' ? $eventId : null,
            'subject' => $subject !== '' ? $subject : null,
        ]);

        if ($deliveries >= self::MAX_PROCESSING_ATTEMPTS) {
            // Best effort: store why it got parked
            if ($eventId !== '') {
                try {
                    EventInbox::query()->updateOrCreate(
                        ['event_id' => $eventId],
                        [
                            'subject'     => $subject !== '' ? $subject : 'unknown',
                            'source'      => is_array($event) ? (($event['source'] ?? null) ?: null) : null,
                            'stream'      => $streamName,
                            'consumer'    => $durable,
                            'payload'     => is_array($event) ? $event : ['raw' => $raw],
                            'processed_at' => null,
                            'last_error'  => "Parked after {$deliveries} deliveries (poison message / handler failing).",
                        ]
                    );
                } catch (Throwable $ignored) {
                    Log::warning('Failed to write poison message into inbox while parking', [
                        'stream' => $streamName,
                        'consumer' => $durable,
                        'event_id' => $eventId,
                        'subject' => $subject,
                        'error' => $ignored->getMessage(),
                    ]);
                }
            }

            $this->ackSafe($msg);

            Log::warning('Poison message parked (max deliveries reached) - ACKed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'deliveries' => $deliveries,
                'event_id' => $eventId !== '' ? $eventId : null,
                'subject' => $subject !== '' ? $subject : null,
            ]);

            return;
        }

        if (!is_array($event)) {
            $this->ackSafe($msg);

            Log::warning('Non-JSON message ACKed (ignored)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'deliveries' => $deliveries,
                'raw_preview' => mb_substr($raw, 0, 200),
            ]);

            return;
        }

        $source  = (string) ($event['source'] ?? '');

        if ($eventId === '' || $subject === '') {
            $this->ackSafe($msg);

            Log::warning('Invalid event envelope ACKed (missing id/subject)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'deliveries' => $deliveries,
                'event_id' => $eventId !== '' ? $eventId : null,
                'subject' => $subject !== '' ? $subject : null,
                'source' => $source !== '' ? $source : null,
            ]);

            return;
        }

        DB::beginTransaction();

        try {
            $inbox = EventInbox::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($inbox && $inbox->processed_at) {
                DB::commit();
                $this->ackSafe($msg);

                Log::debug('Event already processed - ACKed', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                ]);

                return;
            }

            if (!$inbox) {
                $inbox = EventInbox::create([
                    'event_id'      => $eventId,
                    'subject'       => $subject,
                    'source'        => $source ?: null,
                    'stream'        => $streamName,
                    'consumer'      => $durable,
                    'payload'       => $event,
                    'processed_at'  => null,
                    'last_error'    => null,
                ]);

                Log::debug('Event inbox row created', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                ]);
            }

            $handlerClass = $this->router->resolve($subject);

            Log::debug('Resolved handler for subject', [
                'stream' => $streamName,
                'consumer' => $durable,
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
            $this->ackSafe($msg);

            Log::info('Event processed successfully - ACKed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
                'deliveries' => $deliveries,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            // Store the failure; the message will retry until MAX_PROCESSING_ATTEMPTS then get parked.
            try {
                EventInbox::query()->where('event_id', $eventId)->update([
                    'last_error' => $e->getMessage(),
                ]);
            } catch (Throwable $ignored) {
                Log::warning('Failed to update inbox last_error after handler failure', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $subject,
                    'error' => $ignored->getMessage(),
                ]);
            }

            $this->nakSafe($msg);

            Log::error('Event processing failed - NAKed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
                'deliveries' => $deliveries,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * NATS JetStream adds delivery count in headers (typically Nats-Num-Delivered).
     * We read it defensively so it works even if the PHP library changes.
     */
    private function getNumDelivered($msg): int
    {
        try {
            $headers = null;

            if (method_exists($msg, 'getHeaders')) {
                $headers = $msg->getHeaders();
            } elseif (property_exists($msg, 'headers')) {
                $headers = $msg->headers;
            }

            if (is_array($headers)) {
                foreach (['Nats-Num-Delivered', 'NATS-Num-Delivered', 'nats-num-delivered'] as $k) {
                    if (isset($headers[$k])) {
                        $v = $headers[$k];
                        if (is_array($v)) $v = $v[0] ?? null;
                        if (is_string($v) && ctype_digit($v)) return (int) $v;
                        if (is_int($v)) return $v;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // If we can’t read it, return 0 so it behaves like “first attempt”.
        return 0;
    }

    private function extractBody($msg): string
    {
        if (method_exists($msg, 'getBody')) {
            $b = $msg->getBody();
            return is_string($b) ? $b : '';
        }

        if (property_exists($msg, 'body')) {
            $b = $msg->body;
            return is_string($b) ? $b : '';
        }

        if (method_exists($msg, '__toString')) {
            return (string) $msg;
        }

        return '';
    }

    private function ackSafe($msg): void
    {
        try {
            if (method_exists($msg, 'ack')) {
                $msg->ack();
            }
        } catch (Throwable $e) {
            Log::warning('ACK failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nakSafe($msg): void
    {
        try {
            if (method_exists($msg, 'nak')) {
                $msg->nak();
            }
        } catch (Throwable $e) {
            Log::warning('NAK failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
