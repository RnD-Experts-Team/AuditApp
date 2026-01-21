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
            throw new \Exception('UserUpdatedHandler: missing/invalid user.id');
        }

        DB::transaction(function () use ($id, $userPayload) {
            $user = User::query()->where('id', $id)->first();

            // If user doesn’t exist yet, create it with defaults (safe for out-of-order delivery)
            if (!$user) {
                $email = (string) data_get($userPayload, 'email', "user{$id}@placeholder.local");
                $name  = (string) data_get($userPayload, 'name', 'Unknown');

                $role = (string) data_get($userPayload, 'role', 'User');
                if (!in_array($role, ['Admin', 'User'], true)) {
                    $role = 'User';
                }

                $user = User::query()->create([
                    'id' => $id,
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    // required NOT NULL in your migration
                    'password' => bcrypt(\Illuminate\Support\Str::random(48)),
                ]);

                $this->ensureUserGroup($id, 69);
                return;
            }

            $update = [];

            if (data_get($userPayload, 'name') !== null) {
                $update['name'] = (string) data_get($userPayload, 'name');
            }

            if (data_get($userPayload, 'email') !== null) {
                $update['email'] = (string) data_get($userPayload, 'email');
            }

            // role not in payload usually, but support it if present
            if (data_get($userPayload, 'role') !== null) {
                $role = (string) data_get($userPayload, 'role');
                if (in_array($role, ['Admin', 'User'], true)) {
                    $update['role'] = $role;
                }
            }

            if (!empty($update)) {
                $user->update($update);
            }

            // Ensure default group still exists (in case user was created earlier without it)
            $this->ensureUserGroup($id, 69);
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

        // Some systems may send only: data.user_id + changes
        $id = data_get($event, 'data.user_id');
        if ($id !== null) {
            return ['id' => $id] + (is_array(data_get($event, 'data.changed_fields')) ? data_get($event, 'data.changed_fields') : []);
        }

        throw new \Exception('UserUpdatedHandler: user payload not found in event');
    }

    private function ensureUserGroup(int $userId, int $group): void
    {
        $group = (int) $group;

        if (class_exists(\App\Models\UserGroup::class)) {
            $klass = \App\Models\UserGroup::class;

            $klass::query()->updateOrCreate(
                ['user_id' => $userId, 'group' => $group],
                ['user_id' => $userId, 'group' => $group]
            );
            return;
        }

        if (DB::getSchemaBuilder()->hasTable('user_groups')) {
            $exists = DB::table('user_groups')
                ->where('user_id', $userId)
                ->where('group', $group)
                ->exists();

            if (!$exists) {
                DB::table('user_groups')->insert([
                    'user_id' => $userId,
                    'group' => $group,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
