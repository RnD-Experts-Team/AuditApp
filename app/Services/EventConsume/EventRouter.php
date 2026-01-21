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
    ];

    public function resolve(string $subject): string
    {
        if (!isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
