<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\UserStoreRole;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserStoreRoleToggledHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $assignmentId = $this->asInt(
            data_get($event, 'data.assignment_id') ?? data_get($event, 'assignment_id')
        );

        if ($assignmentId <= 0) {
            throw new \Exception('UserStoreRoleToggledHandler: missing/invalid assignment_id');
        }

        $after = data_get($event, 'data.after_is_active');
        if ($after === null) {
            $after = data_get($event, 'after_is_active');
        }

        if (!is_bool($after)) {
            if (is_numeric($after)) {
                $after = ((int) $after) === 1;
            } else {
                throw new \Exception('UserStoreRoleToggledHandler: missing after_is_active');
            }
        }

        DB::transaction(function () use ($assignmentId, $after) {
            UserStoreRole::query()
                ->whereKey($assignmentId)
                ->update(['active' => (bool) $after]);
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
