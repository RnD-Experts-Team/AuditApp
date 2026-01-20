<?php

namespace App\Services\EventConsume;

use App\Models\EventInbox;
use App\Services\Nats\NatsClientFactory;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * JetStream consumer loop for Basis\Nats library.
 *
 * IMPORTANT:
 * - Basis\Nats Consumer does NOT have ->pull().
 * - Pull-style consumption is done via the Consumer Queue:
 *     $queue = $consumer->getQueue();
 *     $queue->setTimeout(...)->fetchAll($batch) or $queue->next()
 */
class JetStreamConsumer
{
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

        $batch = (int) config('nats.pull.batch', 25);
        $timeoutMs = (int) config('nats.pull.timeout_ms', 2000);
        $sleepMs = (int) config('nats.pull.sleep_ms', 250);

        // Basis queue timeout is in seconds (float). Convert ms -> seconds.
        $timeoutSeconds = max(0.001, $timeoutMs / 1000);

        while (true) {
            foreach ($streams as $cfg) {
                $this->consumeStream($cfg, $batch, $timeoutSeconds);
            }

            usleep(max(1, $sleepMs) * 1000);
        }
    }

    /**
     * @param array{name:string,durable:string,filter_subject:string} $cfg
     */
    private function consumeStream(array $cfg, int $batch, float $timeoutSeconds): void
    {
        $streamName = (string) ($cfg['name'] ?? '');
        $durable = (string) ($cfg['durable'] ?? '');
        $filterSubject = (string) ($cfg['filter_subject'] ?? '>');

        if ($streamName === '' || $durable === '') {
            throw new Exception('Stream config requires name + durable');
        }

        $client = $this->factory->make();

        try {
            $api = $client->getApi();
            $stream = $api->getStream($streamName);

            // Ensure durable consumer exists (or is created).
            $consumer = $this->ensureConsumer($stream, $durable, $filterSubject);

            // ✅ Basis pull-consume: use Queue (NOT consumer->pull()).
            $queue = $consumer->getQueue();

            // Set a timeout for batch fetch (seconds).
            $queue->setTimeout($timeoutSeconds);

            // Fetch up to $batch messages (returns array of messages).
            $messages = $queue->fetchAll($batch);

            if (empty($messages)) {
                return;
            }

            foreach ($messages as $msg) {
                $this->handleMessage($msg, $streamName, $durable);
            }

            // NOTE:
            // We intentionally do NOT unsubscribe here so we keep consuming.
            // If you ever need to stop cleanly, call:
            // $client->unsubscribe($queue);
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensures a durable consumer exists for the stream.
     *
     * Basis library pattern:
     * - $stream->getConsumer('name') returns consumer object
     * - configure it (subject filter) and call ->create()
     * - If it already exists, create() is safe and will just ensure it exists.
     */
    private function ensureConsumer($stream, string $durable, string $filterSubject)
    {
        $consumer = $stream->getConsumer($durable);

        // Set filter subject to match your stream subjects.
        // (In your stream you store auth.v1.> so this should match that.)
        $consumer->getConfiguration()->setSubjectFilter($filterSubject);

        // Create the consumer in JetStream if not exists (or ensure it exists).
        $consumer->create();

        return $consumer;
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        $raw = $this->extractBody($msg);
        $event = json_decode($raw, true);

        // If it's not JSON, just ack and move on.
        if (!is_array($event)) {
            $this->ackSafe($msg);
            return;
        }

        // Match your AuthEventFactory envelope
        $eventId = (string) ($event['id'] ?? '');
        $subject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source  = (string) ($event['source'] ?? '');

        if ($eventId === '' || $subject === '') {
            $this->ackSafe($msg);
            return;
        }

        DB::beginTransaction();

        try {
            // lock by event id for idempotency
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
                    'event_id' => $eventId,
                    'subject' => $subject,
                    'source' => $source ?: null,
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'payload' => $event,
                    'processed_at' => null,
                    'last_error' => null,
                ]);
            }

            // Route and execute
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
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractBody($msg): string
    {
        // Basis NATS Message usually has ->payload OR body accessors depending on type.
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
            // ignore
        }
    }

    private function nakSafe($msg): void
    {
        try {
            // Basis uses nack(seconds) sometimes (see library examples).
            if (method_exists($msg, 'nack')) {
                // retry quickly (1 second) to avoid tight loops
                $msg->nack(1);
            } elseif (method_exists($msg, 'nak')) {
                $msg->nak();
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}
