<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionRevokedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $roleId = $event['data']['role_id'] ?? null;
        $names = $event['data']['permissions'] ?? null;

        if (!is_numeric($roleId)) throw new Exception('revoked missing role_id');
        if (!is_array($names) || count($names) === 0) return;

        $role = Role::query()->where('id', (int) $roleId)->first();
        if (!$role) return;

        $permIds = [];

        foreach ($names as $n) {
            if (!is_string($n) || $n === '') continue;
            $p = Permission::query()->where('name', $n)->where('guard_name', $role->guard_name)->first();
            if ($p) $permIds[] = (int) $p->id;
        }

        if (count($permIds) > 0) {
            $role->permissions()->detach($permIds);
        }
    }
}
