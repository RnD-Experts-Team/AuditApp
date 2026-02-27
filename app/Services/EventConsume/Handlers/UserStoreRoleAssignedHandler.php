<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserStoreRoleAssignedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $a = $this->extractAssignmentPayload($event);

        // IMPORTANT: this is the SOURCE OF TRUTH assignment id (must match your migration change: non-increment PK)
        $id      = $this->asInt(data_get($a, 'id'));
        $userId  = $this->asInt(data_get($a, 'user_id'));
        $storeId = $this->asNullableInt(data_get($a, 'store_id')); // null => all stores
        $roleId  = $this->asInt(data_get($a, 'role_id'));

        if ($id <= 0) {
            throw new \Exception('UserStoreRoleAssignedHandler: missing/invalid assignment.id');
        }
        if ($userId <= 0) {
            throw new \Exception('UserStoreRoleAssignedHandler: missing/invalid user_id');
        }
        if ($roleId <= 0) {
            // role_id should always exist; fail closed so we don't store ambiguous data
            throw new \Exception('UserStoreRoleAssignedHandler: missing/invalid role_id');
        }

        // Fail closed: user/store must already exist (replicated elsewhere)
        if (!User::query()->whereKey($userId)->exists()) {
            throw new \Exception("UserStoreRoleAssignedHandler: user {$userId} not synced yet");
        }
        if ($storeId !== null && !Store::query()->whereKey($storeId)->exists()) {
            throw new \Exception("UserStoreRoleAssignedHandler: store {$storeId} not synced yet");
        }

        $active = (bool) data_get($a, 'is_active', true);
        $meta   = data_get($a, 'metadata') ?? data_get($a, 'meta');

        /**
         * Table has role_name NOT NULL.
         * Prefer any provided role_name, otherwise store a stable placeholder based on role_id.
         */
        $roleName = (string) (data_get($a, 'role_name')
            ?? data_get($a, 'role.name')
            ?? ('role_id_' . $roleId));

        DB::transaction(function () use ($id, $userId, $storeId, $roleName, $active, $meta) {
            UserStoreRole::query()->updateOrCreate(
                ['id' => $id],
                [
                    'user_id'   => $userId,
                    'store_id'  => $storeId,
                    'role_name' => $roleName,
                    'active'    => $active,
                    'meta'      => is_array($meta) ? $meta : (is_null($meta) ? null : ['value' => $meta]),
                ]
            );
        });
    }

    private function extractAssignmentPayload(array $event): array
    {
        $a = data_get($event, 'data.assignment');
        if (is_array($a)) return $a;

        $a = data_get($event, 'assignment');
        if (is_array($a)) return $a;

        $a = data_get($event, 'payload.assignment');
        if (is_array($a)) return $a;

        throw new \Exception('UserStoreRoleAssignedHandler: assignment payload not found');
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }

    private function asNullableInt(mixed $v): ?int
    {
        if ($v === null) return null;
        $i = $this->asInt($v);
        return $i > 0 ? $i : null;
    }
}
