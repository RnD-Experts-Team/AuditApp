<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;

class StoreUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storeId = $event['data']['store_id'] ?? null;
        $changed = $event['data']['changed_fields'] ?? null;

        if (!is_numeric($storeId)) throw new Exception('store.updated missing data.store_id');
        if (!is_array($changed)) return;

        $updates = [];

        if (isset($changed['name']['to']) && is_string($changed['name']['to'])) {
            $updates['store'] = $changed['name']['to'];
        }

        if (array_key_exists('metadata', $changed)) {
            $updates['group'] = $this->extractGroup($changed['metadata']['to'] ?? null);
        }

        if (empty($updates)) return;

        Store::query()->updateOrCreate(
            ['id' => (int) $storeId],
            $updates
        );
    }

    private function extractGroup(mixed $meta): int
    {
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if (is_array($meta)) {
            $g = $meta['group'] ?? null;
            if (is_numeric($g)) return (int) $g;
        }

        return 69;
    }
}
