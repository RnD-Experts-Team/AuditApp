<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Role;

class UserRoleRemovedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $event['data']['user_id'] ?? null;
        $roles = $event['data']['roles'] ?? null;

        if (!is_numeric($userId)) {
            throw new Exception('user.role.removed missing user_id');
        }
        if (!is_array($roles)) {
            return;
        }

        $user = User::query()->where('id', (int) $userId)->first();
        if (!$user) {
            throw new Exception("User {$userId} not found. Wait for auth.v1.user.created.");
        }

        $roleNames = $this->normalizeStringList($roles);

        if (count($roleNames) > 0) {
            // Require roles exist (truth IDs already in Role table)
            foreach ($roleNames as $roleName) {
                $exists = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $this->guardName())
                    ->exists();

                if (!$exists) {
                    throw new Exception("Role '{$roleName}' not found in QA yet (must arrive via auth.v1.role.created).");
                }
            }

            foreach ($roleNames as $roleName) {
                $user->removeRole($roleName);
            }
        }

        // keep enum behavior: if Admin removed, downgrade
        foreach ($roleNames as $r) {
            if (strcasecmp($r, 'Admin') === 0) {
                User::query()->where('id', (int) $userId)->update(['role' => 'User']);
                break;
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function guardName(): string
    {
        return 'web';
    }
}
