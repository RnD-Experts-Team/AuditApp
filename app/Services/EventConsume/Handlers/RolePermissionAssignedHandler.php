<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionAssignedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $roleId = $event['data']['role_id'] ?? null;
        $names = $event['data']['permissions'] ?? null;

        if (!is_numeric($roleId)) throw new Exception('assignment missing role_id');
        if (!is_array($names) || count($names) === 0) return;

        $role = Role::query()->where('id', (int) $roleId)->first();
        if (!$role) throw new Exception("Role {$roleId} not found in QA yet");

        $permIds = [];

        foreach ($names as $n) {
            if (!is_string($n) || $n === '') continue;
            $p = Permission::query()->where('name', $n)->where('guard_name', $role->guard_name)->first();

            // If permission not found yet, create it WITHOUT id (we need id!)
            // BUT you demanded exact source IDs — so we must NOT invent ids.
            // Therefore: if not found, fail so you can fix ordering (permission created should arrive first).
            if (!$p) {
                throw new Exception("Permission '{$n}' not found in QA yet (must arrive via role.created snapshot or a permission event).");
            }

            $permIds[] = (int) $p->id;
        }

        if (count($permIds) > 0) {
            $role->permissions()->syncWithoutDetaching($permIds);
        }
    }
}
