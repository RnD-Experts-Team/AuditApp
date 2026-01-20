<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Role;

class UserRoleSyncedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $event['data']['user_id'] ?? null;
        $diff = $event['data']['roles'] ?? null;

        if (!is_numeric($userId)) {
            throw new Exception('user.role.synced missing user_id');
        }
        if (!is_array($diff)) {
            return;
        }

        $final = $diff['to'] ?? null;
        if (!is_array($final)) {
            return;
        }

        $user = User::query()->where('id', (int) $userId)->first();
        if (!$user) {
            throw new Exception("User {$userId} not found. Wait for auth.v1.user.created.");
        }

        $finalRoleNames = $this->normalizeStringList($final);

        // Require roles exist (so we don't invent IDs).
        foreach ($finalRoleNames as $roleName) {
            $exists = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $this->guardName())
                ->exists();

            if (!$exists) {
                throw new Exception("Role '{$roleName}' not found in QA yet (must arrive via auth.v1.role.created).");
            }
        }

        // Spatie exact match.
        $user->syncRoles($finalRoleNames);

        // keep enum behavior (admin if present)
        $roleEnum = 'User';
        foreach ($finalRoleNames as $r) {
            if (strcasecmp($r, 'Admin') === 0) {
                $roleEnum = 'Admin';
                break;
            }
        }

        User::query()->where('id', (int) $userId)->update(['role' => $roleEnum]);
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
