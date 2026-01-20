<?php

namespace App\Services\EventConsume;

use App\Models\EventInbox;
use App\Services\Nats\NatsClientFactory;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        while (true) {
            foreach ($streams as $cfg) {
                $this->consumeStream($cfg, $batch, $timeoutMs);
            }
            usleep(max(1, $sleepMs) * 1000);
        }
    }

    /**
     * @param array{name:string,durable:string,filter_subject:string} $cfg
     */
    private function consumeStream(array $cfg, int $batch, int $timeoutMs): void
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

            $consumer = $this->ensureConsumer($stream, $durable, $filterSubject);

            $messages = $consumer->pull($batch, $timeoutMs);

            if (empty($messages)) return;

            foreach ($messages as $msg) {
                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensureConsumer($stream, string $durable, string $filterSubject)
    {
        try {
            return $stream->getConsumer($durable);
        } catch (Throwable $e) {
            return $stream->createConsumer([
                'durable_name' => $durable,
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
                'max_ack_pending' => 20000,
                'filter_subject' => $filterSubject,
            ]);
        }
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {
        $raw = $this->extractBody($msg);
        $event = json_decode($raw, true);

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
