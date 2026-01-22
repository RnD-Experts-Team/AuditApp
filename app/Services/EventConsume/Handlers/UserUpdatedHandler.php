<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(data_get($event, 'data.user_id') ?? data_get($event, 'user_id'));

        // fallback if some producers send data.user.id
        if ($id <= 0) {
            $id = $this->asInt(data_get($event, 'data.user.id') ?? data_get($event, 'user.id'));
        }

        if ($id <= 0) {
            throw new \Exception('UserUpdatedHandler: missing/invalid user id');
        }

        // Delta-style: data.changed_fields.{field}.{to|from}
        $changed = data_get($event, 'data.changed_fields', []);
        if (!is_array($changed)) {
            $changed = [];
        }

        DB::transaction(function () use ($id, $changed) {
            $user = User::query()->find($id);

            // IMPORTANT: do not create users here (bus is source of truth)
            if (!$user) {
                throw new \Exception("UserUpdatedHandler: user {$id} not synced yet");
            }

            $update = [];

            // Only apply "to" values (delta envelope)
            $nameTo = data_get($changed, 'name.to');
            if ($nameTo !== null) {
                $update['name'] = (string) $nameTo;
            }

            $emailTo = data_get($changed, 'email.to');
            if ($emailTo !== null) {
                $update['email'] = (string) $emailTo;
            }

            // Password should generally NOT be synced to downstream services via events.
            // If you do publish it, it should be handled in a separate secure channel.
            // So we intentionally do nothing here even if password.to exists.

            if (!empty($update)) {
                $user->update($update);
            }
        });
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
