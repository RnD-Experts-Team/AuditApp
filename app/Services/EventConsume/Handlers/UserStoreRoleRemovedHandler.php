<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\UserStoreRole;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserStoreRoleRemovedHandler implements EventHandlerInterface
{
    /**
     * Set this to the role_id you want to replicate in this consumer.
     * Example: 5
     */
    protected static int $replicatedRoleId = 5;

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

        // If we don't have assignment_id, we must target by the composite identifiers.
        if ($assignmentId <= 0) {
            if ($userId <= 0 || $roleId <= 0) {
                throw new \Exception('UserStoreRoleRemovedHandler: missing assignment_id and missing (user_id, role_id)');
            }

            // Only replicate/delete for the configured role_id
            if ($roleId !== static::$replicatedRoleId) {
                return;
            }

            DB::transaction(function () use ($userId, $roleId, $storeId) {
                $q = UserStoreRole::query()
                    ->where('user_id', $userId)
                    ->where('role_name', 'role_id_' . $roleId); // must match what your Assigned handler stores

                // store_id nullable means “all stores”
                if ($storeId === null) {
                    $q->whereNull('store_id');
                } else {
                    $q->where('store_id', $storeId);
                }

                // ✅ delete instead of disabling
                $q->delete();
            });

            return;
        }

        // ✅ delete instead of disabling (idempotent: if not found, nothing happens)
        DB::transaction(function () use ($assignmentId) {
            UserStoreRole::query()
                ->whereKey($assignmentId)
                ->delete();
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
