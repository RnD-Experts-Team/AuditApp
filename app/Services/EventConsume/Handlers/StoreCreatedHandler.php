<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;

class StoreCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $store = $event['data']['store'] ?? null;
        if (!is_array($store)) throw new Exception('store.created missing data.store');

        $id = $store['id'] ?? null;
        $name = (string) ($store['name'] ?? '');

        if (!is_numeric($id)) throw new Exception('store.created missing store.id');
        if ($name === '') throw new Exception('store.created missing store.name');

        $group = $this->extractGroup($store['metadata'] ?? null);

        Store::query()->updateOrCreate(
            ['id' => (int) $id],
            [
                'store' => $name,
                'group' => $group,
            ]
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
