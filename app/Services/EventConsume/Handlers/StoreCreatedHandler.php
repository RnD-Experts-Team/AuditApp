<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storePayload = $this->extractStorePayload($event);

        $id = $this->asInt(data_get($storePayload, 'id'));
        if ($id <= 0) {
            throw new \Exception('StoreCreatedHandler: missing/invalid store.id');
        }

        // IMPORTANT: consumer stores.store must be store_id (manual string), not name.
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

        throw new \Exception('StoreCreatedHandler: store payload not found in event');
    }

    private function extractStoreIdString(array $storePayload): string
    {
        // Prefer store_id (manual string), fallback to anything usable.
        $v = data_get($storePayload, 'store_id');

        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        // Some producers might call it "store" already:
        $v = data_get($storePayload, 'store');
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        // Last resort: if only numeric id exists, store it as string so the record is not broken.
        $id = data_get($storePayload, 'id');
        if (is_scalar($id) && (string) $id !== '') {
            return (string) $id;
        }

        return 'UNKNOWN';
    }

    private function extractGroup(mixed $metadata): ?int
    {
        if (!is_array($metadata)) {
            return null;
        }

        foreach ($metadata as $key => $value) {
            if (!is_string($key)) continue;

            // "Group", "group", "GROUP_ID", "storeGroup", etc.
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
