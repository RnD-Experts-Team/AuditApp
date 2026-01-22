<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userPayload = $this->extractUserPayload($event);

        $id = $this->asInt(data_get($userPayload, 'id'));
        if ($id <= 0) {
            // allow delta envelope: user_id + changed_fields
            $id = $this->asInt(data_get($event, 'data.user_id') ?? data_get($event, 'user_id'));
        }

        if ($id <= 0) {
            throw new \Exception('UserUpdatedHandler: missing/invalid user id');
        }

        DB::transaction(function () use ($id, $userPayload) {
            $user = User::query()->find($id);

            // IMPORTANT: do not create users here (bus is source of truth)
            if (!$user) {
                throw new \Exception("UserUpdatedHandler: user {$id} not synced yet");
            }

            $update = [];

            if (data_get($userPayload, 'name') !== null) {
                $update['name'] = (string) data_get($userPayload, 'name');
            }

            if (data_get($userPayload, 'email') !== null) {
                $update['email'] = (string) data_get($userPayload, 'email');
            }

            if (!empty($update)) {
                $user->update($update);
            }
        });
    }

    private function extractUserPayload(array $event): array
    {
        $user = data_get($event, 'data.user');
        if (is_array($user)) return $user;

        $user = data_get($event, 'user');
        if (is_array($user)) return $user;

        $user = data_get($event, 'payload.user');
        if (is_array($user)) return $user;

        // delta style: data.user_id + data.changed_fields
        $id = data_get($event, 'data.user_id') ?? data_get($event, 'user_id');
        $changed = data_get($event, 'data.changed_fields');
        if ($id !== null && is_array($changed)) {
            return ['id' => $id] + $changed;
        }

        throw new \Exception('UserUpdatedHandler: user payload not found in event');
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
