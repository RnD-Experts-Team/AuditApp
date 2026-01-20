<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;

class PermissionCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $perm = $event['data']['permission'] ?? null;
        if (!is_array($perm)) {
            throw new Exception('permission.created missing data.permission');
        }

        $id = $perm['id'] ?? null;
        $name = (string) ($perm['name'] ?? '');
        $guard = (string) ($perm['guard_name'] ?? 'web');

        if (!is_numeric($id)) {
            throw new Exception('permission.created missing permission.id');
        }
        if ($name === '') {
            throw new Exception('permission.created missing permission.name');
        }

        // Create/update permission with EXACT id from source of truth.
        Permission::query()->updateOrCreate(
            ['id' => (int) $id],
            ['name' => $name, 'guard_name' => $guard]
        );
    }
}
