<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Permission;

class PermissionUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $permissionId = $event['data']['permission_id'] ?? null;
        $changed = $event['data']['changed_fields'] ?? null;

        if (!is_numeric($permissionId)) {
            throw new Exception('permission.updated missing data.permission_id');
        }
        if (!is_array($changed)) {
            return;
        }

        $updates = [];

        if (isset($changed['name']['to']) && is_string($changed['name']['to'])) {
            $updates['name'] = $changed['name']['to'];
        }

        if (isset($changed['guard_name']['to']) && is_string($changed['guard_name']['to'])) {
            $updates['guard_name'] = $changed['guard_name']['to'];
        }

        if (empty($updates)) {
            return;
        }

        // Update by EXACT id.
        Permission::query()->where('id', (int) $permissionId)->update($updates);
    }
}
