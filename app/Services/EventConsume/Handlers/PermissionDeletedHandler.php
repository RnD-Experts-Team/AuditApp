<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;

class PermissionDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $permissionId = $event['data']['permission_id'] ?? null;

        if (!is_numeric($permissionId)) {
            throw new Exception('permission.deleted missing data.permission_id');
        }

        Permission::query()->where('id', (int) $permissionId)->delete();
    }
}
