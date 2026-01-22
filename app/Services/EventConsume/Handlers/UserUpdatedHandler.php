<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(data_get($event, 'data.user_id') ?? data_get($event, 'user_id'));

        // fallback if some producers send data.user.id
        if ($id <= 0) {
            $id = $this->asInt(data_get($event, 'data.user.id') ?? data_get($event, 'user.id'));
        }

        if ($id <= 0) {
            throw new \Exception('UserUpdatedHandler: missing/invalid user id');
        }

        $changed = data_get($event, 'data.changed_fields', []);
        if (!is_array($changed)) {
            $changed = [];
        }

        DB::transaction(function () use ($id, $changed) {
            $user = User::query()->find($id);

            // IMPORTANT: do not create users here (bus is source of truth)
            if (!$user) {
                throw new \Exception("UserUpdatedHandler: user {$id} not synced yet");
            }

            $update = [];

            /**
             * Delta-safe extraction:
             * - supports: changed_fields.name.to
             * - supports: changed_fields.name = "Qa"
             * - ignores arrays/objects that cannot be safely cast to string
             */
            $nameTo = $this->deltaToValue($changed, 'name');
            if ($nameTo !== null) {
                $update['name'] = $nameTo;
            }

            $emailTo = $this->deltaToValue($changed, 'email');
            if ($emailTo !== null) {
                $update['email'] = $emailTo;
            }

            // Password should NOT be synced via events to downstream apps.
            // Intentionally ignored even if present.

            if (!empty($update)) {
                $user->update($update);
            }
        });
    }

    /**
     * Extract the "to" value from a delta payload safely.
     *
     * Accepts either:
     *   changed_fields[field] = ['from' => ..., 'to' => ...]
     *   changed_fields[field] = <scalar>
     *
     * Returns a string for scalar values only.
     * Returns null if the value is missing or not safely convertible.
     */
    private function deltaToValue(array $changed, string $field): ?string
    {
        $v = $changed[$field] ?? null;

        // Most common: ['from' => ..., 'to' => ...]
        if (is_array($v) && array_key_exists('to', $v)) {
            $to = $v['to'];

            // Only accept scalar "to" values
            if (is_string($to)) {
                $to = trim($to);
                return $to === '' ? null : $to;
            }

            if (is_int($to) || is_float($to) || is_bool($to)) {
                return (string) $to;
            }

            // If "to" is an array/object => do not cast (prevents Array to string conversion)
            return null;
        }

        // Some producers might send direct scalar values: changed_fields[field] = "Qa"
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }

        if (is_int($v) || is_float($v) || is_bool($v)) {
            return (string) $v;
        }

        // Anything else (array/object/null) => ignore safely
        return null;
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
