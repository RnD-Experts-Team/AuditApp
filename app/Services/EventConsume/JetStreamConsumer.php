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
        }

        try {
            $api    = $this->client->getApi();
            $stream = $api->getStream($streamName);

            // If you created the consumer via CLI, this will just fetch it.
            // If not, it will create it with sane defaults (but I recommend CLI).
            $consumer = $this->ensureConsumer($stream, $durable, $filterSubject);

            $messages = $consumer->pull($batch, $timeoutMs);
            if (empty($messages)) {
                return;
            }

            foreach ($messages as $msg) {
                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
            ]);

            // Backoff slightly on stream errors to avoid hot-spin
            usleep(self::ERROR_BACKOFF_MS * 1000);
        }
    }

    private function ensureConsumer($stream, string $durable, string $filterSubject)
    {
        try {
            return $stream->getConsumer($durable);
        } catch (Throwable $e) {
            // Fallback: create consumer if missing.
            // NOTE: Prefer creating via NATS CLI so you can control max_deliver/backoff cleanly.
            return $stream->createConsumer([
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
        }
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        // If NATS has already tried delivering this message too many times, ACK + park it.
        $deliveries = $this->getNumDelivered($msg);
        if ($deliveries >= self::MAX_PROCESSING_ATTEMPTS) {
            $raw = $this->extractBody($msg);
            $event = json_decode($raw, true);

            $eventId = is_array($event) ? (string) ($event['id'] ?? '') : '';
            $subject = is_array($event) ? (string) ($event['subject'] ?? $event['type'] ?? '') : '';

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
                            'processed_at'=> null,
                            'last_error'  => "Parked after {$deliveries} deliveries (poison message / handler failing).",
                        ]
                    );
                } catch (Throwable $ignored) {
                }
            }

            $this->ackSafe($msg);
            Log::warning('Poison message parked (max deliveries reached)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'deliveries' => $deliveries,
                'event_id' => $eventId ?: null,
                'subject' => $subject ?: null,
            ]);
            return;
        }

        $raw = $this->extractBody($msg);
        $event = json_decode($raw, true);

        if (!is_array($event)) {
            $this->ackSafe($msg);
            return;
        }

        $eventId = (string) ($event['id'] ?? '');
        $subject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source  = (string) ($event['source'] ?? '');

        if ($eventId === '' || $subject === '') {
            $this->ackSafe($msg);
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
            }

            $handlerClass = $this->router->resolve($subject);
            /** @var EventHandlerInterface $handler */
            $handler = app($handlerClass);
            $handler->handle($event);

            $inbox->processed_at = now();
            $inbox->last_error = null;
            $inbox->save();

            DB::commit();
            $this->ackSafe($msg);
        } catch (Throwable $e) {
            DB::rollBack();

            // Store the failure; the message will retry until MAX_PROCESSING_ATTEMPTS then get parked.
            try {
                EventInbox::query()->where('event_id', $eventId)->update([
                    'last_error' => $e->getMessage(),
                ]);
            } catch (Throwable $ignored) {
            }

            $this->nakSafe($msg);

            Log::error('Event processing failed', [
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
            // Common patterns across clients:
            // - $msg->getHeaders() returns array
            // - $msg->headers is array
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
            if (method_exists($msg, 'ack')) $msg->ack();
        } catch (Throwable $e) {
        }
    }

    private function nakSafe($msg): void
    {
        try {
            if (method_exists($msg, 'nak')) $msg->nak();
        } catch (Throwable $e) {
        }
    }
}
