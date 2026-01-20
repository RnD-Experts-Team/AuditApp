<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $u = $event['data']['user'] ?? null;
        if (!is_array($u)) {
            throw new Exception('user.created missing data.user');
        }

        $id = $u['id'] ?? null;
        $name = (string) ($u['name'] ?? '');
        $email = (string) ($u['email'] ?? '');

        if (!is_numeric($id)) {
            throw new Exception('user.created missing user.id');
        }
        if ($email === '') {
            throw new Exception('user.created missing user.email');
        }

        // 1) Keep your QA enum behavior.
        $rolesByName = $event['data']['roles'] ?? [];
        $roleEnum = $this->inferEnumRole($rolesByName);

        // Upsert user row with exact ID.
        $user = User::query()->updateOrCreate(
            ['id' => (int) $id],
            [
                'name' => $name !== '' ? $name : $email,
                'email' => $email,
                'role' => $roleEnum,

                // required for Laravel auth, QA doesn't use it
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );

        // 2) ALSO mirror Spatie roles (if provided).
        $roleNames = $this->normalizeStringList($rolesByName);

        if (count($roleNames) > 0) {
            // Require role rows exist already (IDs come from role.created).
            foreach ($roleNames as $roleName) {
                $exists = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $this->guardName())
                    ->exists();

                if (!$exists) {
                    throw new Exception("Role '{$roleName}' not found in QA yet (must arrive via auth.v1.role.created).");
                }
            }

            // syncRoles by name (Spatie keeps ids because Role rows already have truth IDs).
            $user->syncRoles($roleNames);
        }

        // 3) ALSO mirror direct permissions (publisher uses permissions_direct on create).
        $directPermNames = $this->normalizeStringList($event['data']['permissions_direct'] ?? []);

        if (count($directPermNames) > 0) {
            // Require permission rows exist already (IDs come from permission.created or role.created snapshot).
            foreach ($directPermNames as $permName) {
                $p = Permission::query()
                    ->where('name', $permName)
                    ->where('guard_name', $this->guardName())
                    ->first();

                if (!$p) {
                    throw new Exception("Permission '{$permName}' not found in QA yet (must arrive via auth.v1.permission.created or role.created snapshot).");
                }
            }

            $user->syncPermissions($directPermNames);
        }
    }

    private function inferEnumRole(mixed $roles): string
    {
        if (is_array($roles)) {
            foreach ($roles as $r) {
                if (is_string($r) && strcasecmp($r, 'Admin') === 0) {
                    return 'Admin';
                }
            }
        }
        return 'User';
    }

    /**
     * Normalize a list of string values safely.
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
        // Must match your publisher defaults (web).
        return 'web';
    }
}
