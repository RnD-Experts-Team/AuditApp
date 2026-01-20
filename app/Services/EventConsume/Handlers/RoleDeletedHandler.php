<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Role;

class RoleDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $roleId = $event['data']['role_id'] ?? null;
        if (!is_numeric($roleId)) throw new Exception('role.deleted missing data.role_id');

        Role::query()->where('id', (int) $roleId)->delete();
    }
}
