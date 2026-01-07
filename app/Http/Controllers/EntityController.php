<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class EntityController extends Controller
{
    /**
     * Display entities and categories management page.
     */
    public function index()
    {
        // Order categories by sort_order (nulls last), then alphabetically
        $categories = Category::withCount('entities')
            ->orderByRaw('sort_order IS NULL, sort_order ASC')
            ->orderBy('label')
            ->get();

        // Order entities by category sort_order, then entity sort_order, then alphabetically
        $entities = Entity::with('category')
            ->leftJoin('categories', 'entities.category_id', '=', 'categories.id')
            ->select('entities.*')
            ->orderByRaw('categories.sort_order IS NULL, categories.sort_order ASC')
            ->orderByRaw('entities.sort_order IS NULL, entities.sort_order ASC')
            ->orderBy('entities.entity_label')
            ->get();

        return Inertia::render('Entities/Index', [
            'entities' => $entities,
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created entity.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'entity_label' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'date_range_type' => 'required|in:daily,weekly',
            'report_type' => 'nullable|in:main,secondary',
            'sort_order' => 'nullable|integer|min:0',
            'active' => 'required|boolean',
        ]);

        Entity::create($validated);

        return back()->with('success', 'Entity created successfully.');
    }

    /**
     * Update the specified entity.
     */
    public function update(Request $request, $id)
    {
        $entity = Entity::findOrFail($id);

        $validated = $request->validate([
            'entity_label' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'date_range_type' => 'required|in:daily,weekly',
            'report_type' => 'nullable|in:main,secondary',
            'sort_order' => 'nullable|integer|min:0',
            'active' => 'required|boolean',
        ]);

        $entity->update($validated);

        return back()->with('success', 'Entity updated successfully.');
    }

    /**
     * Remove the specified entity.
     */
    public function destroy($id)
    {
        try {
            $entity = Entity::findOrFail($id);

            // Check if entity has related camera forms
            if ($entity->cameraForms()->exists()) {
                return back()->withErrors([
                    'error' => 'Cannot delete entity with existing camera forms.'
                ]);
            }

            $entity->delete();

            return back()->with('success', 'Entity deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Delete entity failed: ' . $e->getMessage());

            return back()->withErrors([
                'error' => 'Failed to delete entity: ' . $e->getMessage()
            ]);
        }
    }
}
