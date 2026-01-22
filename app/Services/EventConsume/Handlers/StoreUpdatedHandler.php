<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(data_get($event, 'data.store_id') ?? data_get($event, 'store_id'));

        // fallback if producer sends data.store.id
        if ($id <= 0) {
            $id = $this->asInt(data_get($event, 'data.store.id') ?? data_get($event, 'store.id'));
        }

        if ($id <= 0) {
            throw new \Exception('StoreUpdatedHandler: missing/invalid store id');
        }

        $changed = data_get($event, 'data.changed_fields', []);
        if (!is_array($changed)) {
            $changed = [];
        }

        // Pull "to" values only
        $metadataTo = data_get($changed, 'metadata.to');
        $isActiveTo = data_get($changed, 'is_active.to');

        // If metadata is present, try to extract group from it
        $group = $this->extractGroup($metadataTo);

        DB::transaction(function () use ($id, $group, $isActiveTo) {
            /** @var Store $store */
            $store = Store::query()->find($id);

            // If your downstream DB must not create stores until "created" event arrives:
            // then throw here instead of updateOrCreate.
            if (!$store) {
                // If you DO want to allow eventual consistency, keep updateOrCreate behavior.
                $store = new Store();
                $store->id = $id;
            }

            $update = [];

            // Only update group if we extracted one from metadata
            if ($group !== null) {
                $update['group'] = (int) $group;
            }

            // Only update is_active if it was provided
            if ($isActiveTo !== null) {
                $update['is_active'] = (bool) $isActiveTo;
            }

            if (!empty($update)) {
                // If it's a new model instance with preset id
                if (!$store->exists) {
                    $store->fill($update);
                    $store->save();
                } else {
                    $store->update($update);
                }
            }
        });
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
