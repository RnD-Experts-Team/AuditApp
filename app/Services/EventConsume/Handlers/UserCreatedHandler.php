<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userPayload = $this->extractUserPayload($event);

        $id = $this->asInt(data_get($userPayload, 'id'));
        if ($id <= 0) {
            throw new \Exception('UserCreatedHandler: missing/invalid user.id');
        }

        $email = (string) data_get($userPayload, 'email', '');
        if ($email === '') {
            // If you ever get a user without email, your consumer schema will break uniqueness anyway.
            throw new \Exception('UserCreatedHandler: missing user.email');
        }

        $name = (string) data_get($userPayload, 'name', 'Unknown');

        // Payload does not include role/groups => defaults
        $role = (string) data_get($userPayload, 'role', 'User');
        if (!in_array($role, ['Admin', 'User'], true)) {
            $role = 'User';
        }

        // Consumer schema requires password (NOT NULL). Payload doesn’t include it.
        $password = (string) data_get($userPayload, 'password', '');
        if ($password === '') {
            $password = Hash::make(Str::random(48));
        } else {
            // if they ever send a plain password, hash it
            if (!Str::startsWith($password, '$2y$') && !Str::startsWith($password, '$2a$') && !Str::startsWith($password, '$2b$')) {
                $password = Hash::make($password);
            }
        }

        DB::transaction(function () use ($id, $name, $email, $role, $password) {
            User::query()->updateOrCreate(
                ['id' => $id],
                [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'password' => $password,
                ]
            );

            // Default group assignment (if table exists)
            $this->ensureUserGroup($id, 69);
        });
    }

    private function extractUserPayload(array $event): array
    {
        // Typical envelope patterns we support:
        // 1) $event['data']['user']
        // 2) $event['user']
        // 3) $event['payload']['user']
        $user = data_get($event, 'data.user');
        if (is_array($user)) return $user;

        $user = data_get($event, 'user');
        if (is_array($user)) return $user;

        $user = data_get($event, 'payload.user');
        if (is_array($user)) return $user;

        throw new \Exception('UserCreatedHandler: user payload not found in event');
    }

    private function ensureUserGroup(int $userId, int $group): void
    {
        $group = (int) $group;

        // If you have a model:
        if (class_exists(\App\Models\UserGroup::class)) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $klass */
            $klass = \App\Models\UserGroup::class;

            $klass::query()->updateOrCreate(
                ['user_id' => $userId, 'group' => $group],
                ['user_id' => $userId, 'group' => $group]
            );
            return;
        }

        // If model doesn't exist, but table does:
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
