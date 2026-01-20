<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;

class UserUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $event['data']['user_id'] ?? null;
        $changed = $event['data']['changed_fields'] ?? null;

        if (!is_numeric($userId)) throw new Exception('user.updated missing data.user_id');
        if (!is_array($changed)) return;

        $updates = [];

        if (isset($changed['name']['to']) && is_string($changed['name']['to'])) {
            $updates['name'] = $changed['name']['to'];
        }

        if (isset($changed['email']['to']) && is_string($changed['email']['to'])) {
            $updates['email'] = $changed['email']['to'];
        }

        // You might emit password/email_verified_at deltas. QA can ignore safely.

        if (empty($updates)) return;

        User::query()->where('id', (int) $userId)->update($updates);
    }
}
