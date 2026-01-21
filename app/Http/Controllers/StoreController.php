<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StoreController extends Controller
{
    /**
     * Display a listing of stores.
     */
    public function index()
    {
        $stores = Store::query()
            ->orderBy('group')
            ->orderBy('store')
            ->get();

        $groups = Store::query()
            ->select('group')
            ->distinct()
            ->whereNotNull('group')
            ->orderBy('group')
            ->pluck('group')
            ->values()
            ->toArray();

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
            'groups' => $groups,
        ]);
    }

    /**
     * Store a newly created store.
     */
    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'store' => 'required|string|max:255|unique:stores,store',
    //         'group' => 'nullable|integer|min:1',
    //     ]);

    //     Store::create($validated);

    //     return redirect()->route('stores.index')
    //         ->with('success', 'Store created successfully.');
    // }

    /**
 * Update the specified store.
 */
    // public function update(Request $request, $id)
    // {
    //     $store = Store::findOrFail($id);

    //     $validated = $request->validate([
    //         'store' => [
    //             'required',
    //             'string',
    //             'max:255',
    //             Rule::unique('stores', 'store')->ignore($store->id)
    //         ],
    //         'group' => 'nullable|integer|min:1',
    //     ]);

    //     $store->update($validated);

    //     return redirect()->route('stores.index')
    //         ->with('success', 'Store updated successfully.');
    // }

    /**
 * Remove the specified store.
 */
    // public function destroy($id)
    // {
    //     $store = Store::findOrFail($id);

    //     // Check if store has related camera forms
    //     $hasAudits = $store->audits()->exists();

    //     if ($hasAudits) {
    //         return back()->withErrors([
    //             'error' => 'Cannot delete store with existing camera forms. Please delete related forms first.'
    //         ]);
    //     }

    //     $store->delete();

    //     return redirect()->route('stores.index')
    //         ->with('success', 'Store deleted successfully.');
    // }
}
