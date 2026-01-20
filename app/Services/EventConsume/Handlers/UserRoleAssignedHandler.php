<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Spatie\Permission\Models\Role;

class UserRoleAssignedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $event['data']['user_id'] ?? null;
        $roles = $event['data']['roles'] ?? null;

        if (!is_numeric($userId)) {
            throw new Exception('user.role.assigned missing user_id');
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
            foreach ($roleNames as $roleName) {
                $exists = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $this->guardName())
                    ->exists();

                if (!$exists) {
                    throw new Exception("Role '{$roleName}' not found in QA yet (must arrive via auth.v1.role.created).");
                }
            }

            // assignRole is additive; event is "assigned"
            $user->assignRole($roleNames);
        }

        // keep your enum behavior
        if ($this->containsAdmin($roleNames)) {
            User::query()->where('id', (int) $userId)->update(['role' => 'Admin']);
        }
    }

    private function containsAdmin(array $roles): bool
    {
        foreach ($roles as $r) {
            if (is_string($r) && strcasecmp($r, 'Admin') === 0) {
                return true;
            }
        }
        return false;
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
