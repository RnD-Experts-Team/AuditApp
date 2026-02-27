<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\UserStoreRole;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserStoreRoleRemovedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        // Best case: assignment_id is present (points to the same id we store)
        $assignmentId = $this->asInt(
            data_get($event, 'data.assignment_id')
                ?? data_get($event, 'assignment_id')
                ?? data_get($event, 'data.assignment.id')
                ?? data_get($event, 'assignment.id')
        );

        // Fallback: sometimes producers only send (user_id, role_id, store_id)
        $userId  = $this->asInt(data_get($event, 'data.user_id') ?? data_get($event, 'user_id'));
        $roleId  = $this->asInt(data_get($event, 'data.role_id') ?? data_get($event, 'role_id'));
        $storeId = $this->asNullableInt(data_get($event, 'data.store_id') ?? data_get($event, 'store_id'));

        // Optional: some producers might include role_name (or role.name) even on removed events.
        $roleNameFromEvent = data_get($event, 'data.role_name')
            ?? data_get($event, 'role_name')
            ?? data_get($event, 'data.role.name')
            ?? data_get($event, 'role.name');

        // If we have assignment_id, delete by primary key (idempotent)
        if ($assignmentId > 0) {
            DB::transaction(function () use ($assignmentId) {
                UserStoreRole::query()
                    ->whereKey($assignmentId)
                    ->delete();
            });

            return;
        }

        // No assignment_id: we must target by identifiers.
        if ($userId <= 0 || $roleId <= 0) {
            throw new \Exception('UserStoreRoleRemovedHandler: missing assignment_id and missing (user_id, role_id)');
        }

        // Must match what your Assigned handler stores when no role_name is provided.
        $fallbackRoleName = 'role_id_' . $roleId;

        DB::transaction(function () use ($userId, $storeId, $roleNameFromEvent, $fallbackRoleName) {
            $q = UserStoreRole::query()->where('user_id', $userId);

            // store_id nullable means “all stores”
            if ($storeId === null) {
                $q->whereNull('store_id');
            } else {
                $q->where('store_id', $storeId);
            }

            // If role_name exists in the event, prefer deleting by that exact name;
            // otherwise delete by our placeholder scheme.
            if (is_string($roleNameFromEvent) && $roleNameFromEvent !== '') {
                $q->where('role_name', $roleNameFromEvent);
            } else {
                $q->where('role_name', $fallbackRoleName);
            }

            // ✅ delete instead of disabling
            $q->delete();
        });
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
