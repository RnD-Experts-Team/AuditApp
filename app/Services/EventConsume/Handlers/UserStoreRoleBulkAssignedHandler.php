<?php

namespace App\Services\EventConsume\Handlers;

use App\Services\EventConsume\EventHandlerInterface;

class UserStoreRoleBulkAssignedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $assignments = data_get($event, 'data.assignments')
            ?? data_get($event, 'assignments')
            ?? data_get($event, 'payload.assignments');

        if (!is_array($assignments)) {
            throw new \Exception('UserStoreRoleBulkAssignedHandler: assignments payload not found');
        }

        /** @var UserStoreRoleAssignedHandler $single */
        $single = app(UserStoreRoleAssignedHandler::class);

        foreach ($assignments as $a) {
            if (!is_array($a)) continue;
            $single->handle(['assignment' => $a]);
        }
    }
}
