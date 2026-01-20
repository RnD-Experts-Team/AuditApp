<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $role = $event['data']['role'] ?? null;
        if (!is_array($role)) throw new Exception('role.created missing data.role');

        $id = $role['id'] ?? null;
        $name = (string) ($role['name'] ?? '');
        $guard = (string) ($role['guard_name'] ?? 'web');

        if (!is_numeric($id)) throw new Exception('role.created missing role.id');
        if ($name === '') throw new Exception('role.created missing role.name');

        // Create/update role with exact id
        $r = Role::query()->updateOrCreate(
            ['id' => (int) $id],
            ['name' => $name, 'guard_name' => $guard]
        );

        // snapshot permissions (each has id + name + guard_name)
        $perms = $role['permissions'] ?? [];
        if (is_array($perms) && count($perms) > 0) {
            $permIds = [];

            foreach ($perms as $p) {
                if (!is_array($p)) continue;

                $pid = $p['id'] ?? null;
                $pname = (string) ($p['name'] ?? '');
                $pguard = (string) ($p['guard_name'] ?? $guard);

                if (!is_numeric($pid) || $pname === '') continue;

                Permission::query()->updateOrCreate(
                    ['id' => (int) $pid],
                    ['name' => $pname, 'guard_name' => $pguard]
                );

                $permIds[] = (int) $pid;
            }

            // sync by IDs
            if (count($permIds) > 0) {
                $r->permissions()->sync($permIds);
            }
        }
    }
}
