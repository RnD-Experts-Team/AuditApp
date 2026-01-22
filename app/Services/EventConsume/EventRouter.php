<?php

namespace App\Services\EventConsume;

use Exception;

class EventRouter
{
    /** @var array<string, class-string<EventHandlerInterface>> */
    private array $map = [
        // USERS
        'auth.v1.user.created' => \App\Services\EventConsume\Handlers\UserCreatedHandler::class,
        'auth.v1.user.updated' => \App\Services\EventConsume\Handlers\UserUpdatedHandler::class,
        'auth.v1.user.deleted' => \App\Services\EventConsume\Handlers\UserDeletedHandler::class,

        // STORES
        'auth.v1.store.created' => \App\Services\EventConsume\Handlers\StoreCreatedHandler::class,
        'auth.v1.store.updated' => \App\Services\EventConsume\Handlers\StoreUpdatedHandler::class,
        'auth.v1.store.deleted' => \App\Services\EventConsume\Handlers\StoreDeletedHandler::class,

        // ASSIGNMENTS => replicate qa_auditor into user_store_roles
        'auth.v1.assignment.user_role_store.assigned'      => \App\Services\EventConsume\Handlers\UserStoreRoleAssignedHandler::class,
        'auth.v1.assignment.user_role_store.removed'       => \App\Services\EventConsume\Handlers\UserStoreRoleRemovedHandler::class,
        'auth.v1.assignment.user_role_store.toggled'       => \App\Services\EventConsume\Handlers\UserStoreRoleToggledHandler::class,
        'auth.v1.assignment.user_role_store.bulk_assigned' => \App\Services\EventConsume\Handlers\UserStoreRoleBulkAssignedHandler::class,
    ];

    public function resolve(string $subject): string
    {
        if (!isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
