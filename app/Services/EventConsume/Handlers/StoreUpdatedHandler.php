<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storePayload = $this->extractStorePayload($event);

        $id = $this->asInt(data_get($storePayload, 'id'));
        if ($id <= 0) {
            // some update events might be delta: data.store_id + changed_fields
            $id = $this->asInt(data_get($event, 'data.store_id') ?? data_get($event, 'store_id'));
        }

        if ($id <= 0) {
            throw new \Exception('StoreUpdatedHandler: missing/invalid store id');
        }

        $storeIdString = $this->extractStoreIdString($storePayload);

        $metadata = data_get($storePayload, 'metadata');
        $group = $this->extractGroup($metadata) ?? 69;

        DB::transaction(function () use ($id, $storeIdString, $group) {
            Store::query()->updateOrCreate(
                ['id' => $id],
                [
                    'store' => $storeIdString,
                    'group' => (int) $group,
                ]
            );
        });
    }

    private function extractStorePayload(array $event): array
    {
        $store = data_get($event, 'data.store');
        if (is_array($store)) return $store;

        $store = data_get($event, 'store');
        if (is_array($store)) return $store;

        $store = data_get($event, 'payload.store');
        if (is_array($store)) return $store;

        // delta-style payload: data.changed_fields + data.store_id
        $changed = data_get($event, 'data.changed_fields');
        if (is_array($changed)) {
            $id = data_get($event, 'data.store_id') ?? data_get($event, 'store_id');
            return ['id' => $id] + $changed;
        }

        // If nothing, return empty so we can still delete gracefully elsewhere
        return [];
    }

    private function extractStoreIdString(array $storePayload): string
    {
        $v = data_get($storePayload, 'store_id');
        if (is_string($v) && trim($v) !== '') return trim($v);

        $v = data_get($storePayload, 'store');
        if (is_string($v) && trim($v) !== '') return trim($v);

        $id = data_get($storePayload, 'id');
        if (is_scalar($id) && (string) $id !== '') return (string) $id;

        return 'UNKNOWN';
    }

    private function extractGroup(mixed $metadata): ?int
    {
        if (!is_array($metadata)) {
            return null;
        }

        foreach ($metadata as $key => $value) {
            if (!is_string($key)) continue;

            if (preg_match('/group/i', $key) === 1) {
                if (is_int($value)) return $value;
                if (is_string($value) && is_numeric(trim($value))) return (int) trim($value);
                if (is_numeric($value)) return (int) $value;
            }
        }

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
