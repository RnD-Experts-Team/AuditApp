<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSyncedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $roleId = $event['data']['role_id'] ?? null;
        $final = $event['data']['final'] ?? null;

        if (!is_numeric($roleId)) throw new Exception('synced missing role_id');
        if (!is_array($final)) return;

        $role = Role::query()->where('id', (int) $roleId)->first();
        if (!$role) throw new Exception("Role {$roleId} not found in QA yet");

        $permIds = [];

        foreach ($final as $n) {
            if (!is_string($n) || $n === '') continue;
            $p = Permission::query()->where('name', $n)->where('guard_name', $role->guard_name)->first();

            // same rule: do NOT invent IDs
            if (!$p) {
                throw new Exception("Permission '{$n}' not found in QA yet (must arrive via snapshot/permission event).");
            }

            $permIds[] = (int) $p->id;
        }

        $role->permissions()->sync($permIds);
    }
}
