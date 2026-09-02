<?php

namespace App\Http\Controllers\Api\Cleaning;

use App\Http\Controllers\Controller;
use App\Models\InspectionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspectionItemController extends Controller
{
    public function index(): JsonResponse
    {
        $items = InspectionItem::query()
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            // 0 is not allowed — an item worth nothing is meaningless; use
            // active=false to retire one. Default 1 keeps every existing item's
            // scoring identical to the old unweighted count.
            'weight'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        // Uniqueness is handled here (including soft-deleted rows) so that
        // re-adding a previously removed item RESTORES it (its old cells return)
        // instead of failing the DB unique constraint.
        $existing = InspectionItem::withTrashed()->where('name', $data['name'])->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                $existing->update(array_filter([
                    'active' => true,
                    'weight' => $data['weight'] ?? null,
                ], fn ($v) => $v !== null));
                return response()->json(['data' => $existing, 'restored' => true]);
            }
            return response()->json([
                'message' => 'An item with this name already exists.',
                'errors'  => ['name' => ['An item with this name already exists.']],
            ], 422);
        }

        $item = InspectionItem::create([
            'name'       => $data['name'],
            'weight'     => $data['weight'] ?? 1,
            'sort_order' => $data['sort_order'] ?? (int) (InspectionItem::max('sort_order') + 1),
            'active'     => true,
        ]);

        return response()->json(['data' => $item], 201);
    }

    /**
     * Edit an item — needed above all for `weight`, which previously could only
     * be changed by deleting the item and re-creating it (losing its history).
     *
     * Changing a weight only affects FUTURE grading: every graded cell keeps the
     * weight it was snapshotted with, so no evaluation that already went out to a
     * store can be re-scored from here.
     */
    public function update(Request $request, InspectionItem $inspection_item): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'weight'     => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['sometimes', 'required', 'integer'],
            'active'     => ['sometimes', 'required', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $clash = InspectionItem::withTrashed()
                ->where('name', $data['name'])
                ->whereKeyNot($inspection_item->id)
                ->exists();

            if ($clash) {
                return response()->json([
                    'message' => 'An item with this name already exists.',
                    'errors'  => ['name' => ['An item with this name already exists.']],
                ], 422);
            }
        }

        $inspection_item->update($data);

        return response()->json(['data' => $inspection_item->fresh()]);
    }

    /**
     * Soft delete: the column disappears from the grid, but its past
     * evaluation cells stay in the DB (not cascade-deleted).
     */
    public function destroy(InspectionItem $inspection_item): JsonResponse
    {
        $inspection_item->delete();

        return response()->json(['deleted' => true]);
    }
}
