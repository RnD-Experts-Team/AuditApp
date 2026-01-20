<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class UserPermissionRevokedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $data = $event['data'] ?? null;

        if (!is_array($data)) {
            throw new Exception('Invalid event: missing data.');
        }

        $userId = $data['user_id'] ?? null;
        $permissions = $data['permissions'] ?? null;

        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            throw new Exception('Invalid event: data.user_id must be an integer.');
        }

        $userId = (int) $userId;

        if (!is_array($permissions) || count($permissions) === 0) {
            return;
        }

        $permissionNames = $this->normalizeStringList($permissions);

        if (count($permissionNames) === 0) {
            return;
        }

        DB::transaction(function () use ($userId, $permissionNames) {
            $user = User::query()->where('id', $userId)->first();

            if (!$user) {
                throw new Exception("User {$userId} not found. Wait for auth.v1.user.created.");
            }

            $guard = $this->guardName();

            foreach ($permissionNames as $name) {
                $perm = Permission::query()
                    ->where('name', $name)
                    ->where('guard_name', $guard)
                    ->first();

                if (!$perm) {
                    throw new Exception("Permission '{$name}' not found in QA yet (must arrive via auth.v1.permission.created or role.created snapshot).");
                }

                $user->revokePermissionTo($perm);
            }
        });
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
