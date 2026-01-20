<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;

class UserDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $event['data']['user_id'] ?? null;
        if (!is_numeric($userId)) throw new Exception('user.deleted missing data.user_id');

        User::query()->where('id', (int) $userId)->delete();
    }
}
