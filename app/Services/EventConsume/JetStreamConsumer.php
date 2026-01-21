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

        // Basis Queue timeout uses seconds (float). Convert ms -> seconds.
        $timeoutSeconds = max(0.001, $timeoutMs / 1000);

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
            'timeout_seconds' => $timeoutSeconds,
            'sleep_ms' => $sleepMs,
            'max_processing_attempts' => self::MAX_PROCESSING_ATTEMPTS,
        ]);

        while (true) {
            try {
                foreach ($streams as $cfg) {
                    $this->consumeStream($cfg, $batch, $timeoutSeconds);
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
    private function consumeStream(array $cfg, int $batch, float $timeoutSeconds): void
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

            $consumer = $this->ensureConsumer($stream, $durable, $filterSubject);

            // ✅ BASIS pull-mode: use queue (NOT Consumer::pull()).
            $queue = $consumer->getQueue();
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
                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
            ]);

            usleep(self::ERROR_BACKOFF_MS * 1000);
        }
    }

    private function ensureConsumer($stream, string $durable, string $filterSubject)
    {
        try {
            $c = $stream->getConsumer($durable);

            // IMPORTANT: ensure filter is what you expect.
            // Some versions allow setting config before create; but getConsumer returns existing.
            // We'll log what we *expect* here.
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

            $c = $stream->createConsumer([
                'durable_name'    => $durable,
                'ack_policy'      => 'explicit',
                'deliver_policy'  => 'all',
                'filter_subject'  => $filterSubject,
                'max_ack_pending' => 20000,
            ]);

            Log::info('JetStream consumer created', [
                'durable' => $durable,
                'filter_subject' => $filterSubject,
            ]);

            return $c;
        }
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        $raw = $this->extractBody($msg);
        $event = json_decode($raw, true);

        if (!is_array($event)) {
            $this->ackSafe($msg);
            Log::warning('Non-JSON message ACKed (ignored)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'raw_preview' => mb_substr($raw, 0, 200),
            ]);
            return;
        }

        $eventId = (string) ($event['id'] ?? '');
        $subject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source  = (string) ($event['source'] ?? '');

        if ($eventId === '' || $subject === '') {
            $this->ackSafe($msg);
            Log::warning('Invalid event envelope ACKed (missing id/subject)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId !== '' ? $eventId : null,
                'subject' => $subject !== '' ? $subject : null,
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
            // Ensure inbox row exists + lock it (idempotency + attempt counter)
            $inbox = EventInbox::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (!$inbox) {
                EventInbox::create([
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

            // If it was already parked, never retry again.
            if ($inbox && $inbox->parked_at) {
                DB::commit();
                $this->ackSafe($msg);

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

            // If already processed, ACK and exit.
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

            // Route and execute
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
            $this->ackSafe($msg);

            Log::info('Event processed successfully - ACKed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
            ]);
        } catch (Throwable $e) {
            // We failed processing: increment attempts and decide ACK vs NAK
            try {
                /** @var EventInbox|null $locked */
                $locked = EventInbox::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($locked) {
                    $locked->attempts = (int) $locked->attempts + 1;
                    $locked->last_error = $e->getMessage();

                    // If attempts exceeded, PARK + ACK (stop retrying forever)
                    if ($locked->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                        $locked->parked_at = now();
                        $locked->save();

                        DB::commit();
                        $this->ackSafe($msg);

                        Log::error('Event parked after max attempts - ACKed (stop retrying)', [
                            'stream' => $streamName,
                            'consumer' => $durable,
                            'event_id' => $eventId,
                            'subject' => $subject,
                            'attempts' => (int) $locked->attempts,
                            'error' => $e->getMessage(),
                        ]);

                        return;
                    }

                    // Still allowed to retry
                    $locked->save();

                    DB::commit();
                    $this->nakSafe($msg);

                    Log::error('Event failed - NAKed (will retry)', [
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
                $this->nakSafe($msg);

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
            $this->nakSafe($msg);

            Log::error('Event processing failed - NAKed (fallback)', [
                'stream' => $streamName,
                'consumer' => $durable,
                'event_id' => $eventId,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractBody($msg): string
    {
        if (property_exists($msg, 'payload')) {
            $b = $msg->payload;
            return is_string($b) ? $b : (string) $b;
        }

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
            Log::warning('ACK failed', ['error' => $e->getMessage()]);
        }
    }

    private function nakSafe($msg): void
    {
        try {
            // Some libs use nack(), some nak(). We keep nak() since that's what your code used.
            if (method_exists($msg, 'nack')) {
                $msg->nack(1);
                return;
            }

            if (method_exists($msg, 'nak')) {
                $msg->nak();
            }
        } catch (Throwable $e) {
            Log::warning('NAK failed', ['error' => $e->getMessage()]);
        }
    }
}
