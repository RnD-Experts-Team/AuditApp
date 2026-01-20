<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Exception;

class StoreDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storeId = $event['data']['store_id'] ?? null;
        if (!is_numeric($storeId)) throw new Exception('store.deleted missing data.store_id');

        Store::query()->where('id', (int) $storeId)->delete();
    }
}
