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

        // PERMISSIONS (Spatie)
        'auth.v1.permission.created' => \App\Services\EventConsume\Handlers\PermissionCreatedHandler::class,
        'auth.v1.permission.updated' => \App\Services\EventConsume\Handlers\PermissionUpdatedHandler::class,
        'auth.v1.permission.deleted' => \App\Services\EventConsume\Handlers\PermissionDeletedHandler::class,

        // ROLES (Spatie)
        'auth.v1.role.created' => \App\Services\EventConsume\Handlers\RoleCreatedHandler::class,
        'auth.v1.role.updated' => \App\Services\EventConsume\Handlers\RoleUpdatedHandler::class,
        'auth.v1.role.deleted' => \App\Services\EventConsume\Handlers\RoleDeletedHandler::class,

        // ASSIGNMENTS (role-permission)
        'auth.v1.assignment.role_permission.assigned' => \App\Services\EventConsume\Handlers\RolePermissionAssignedHandler::class,
        'auth.v1.assignment.role_permission.revoked'  => \App\Services\EventConsume\Handlers\RolePermissionRevokedHandler::class,
        'auth.v1.assignment.role_permission.synced'   => \App\Services\EventConsume\Handlers\RolePermissionSyncedHandler::class,

        // USER ROLE events (keep enum + ALSO mirror Spatie role assignments)
        'auth.v1.user.role.assigned' => \App\Services\EventConsume\Handlers\UserRoleAssignedHandler::class,
        'auth.v1.user.role.removed'  => \App\Services\EventConsume\Handlers\UserRoleRemovedHandler::class,
        'auth.v1.user.role.synced'   => \App\Services\EventConsume\Handlers\UserRoleSyncedHandler::class,

        // USER PERMISSION events (must not create permissions)
        'auth.v1.user.permission.granted' => \App\Services\EventConsume\Handlers\UserPermissionGrantedHandler::class,
        'auth.v1.user.permission.revoked' => \App\Services\EventConsume\Handlers\UserPermissionRevokedHandler::class,
        'auth.v1.user.permission.synced'  => \App\Services\EventConsume\Handlers\UserPermissionSyncedHandler::class,

        // STORES
        'auth.v1.store.created' => \App\Services\EventConsume\Handlers\StoreCreatedHandler::class,
        'auth.v1.store.updated' => \App\Services\EventConsume\Handlers\StoreUpdatedHandler::class,
        'auth.v1.store.deleted' => \App\Services\EventConsume\Handlers\StoreDeletedHandler::class,
    ];

    public function resolve(string $subject): string
    {
        if (!isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
