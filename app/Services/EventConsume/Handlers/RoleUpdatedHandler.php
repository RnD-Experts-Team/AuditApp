<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Role;

class RoleUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $roleId = $event['data']['role_id'] ?? null;
        $changed = $event['data']['changed_fields'] ?? null;

        if (!is_numeric($roleId)) throw new Exception('role.updated missing data.role_id');
        if (!is_array($changed)) return;

        $updates = [];

        if (isset($changed['name']['to']) && is_string($changed['name']['to'])) {
            $updates['name'] = $changed['name']['to'];
        }

        if (isset($changed['guard_name']['to']) && is_string($changed['guard_name']['to'])) {
            $updates['guard_name'] = $changed['guard_name']['to'];
        }

        if (empty($updates)) return;

        Role::query()->where('id', (int) $roleId)->update($updates);
    }
}
