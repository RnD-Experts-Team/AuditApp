<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255|unique:categories,label',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        Category::create($validated);

        return back()->with('success', 'Category created successfully.');
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'label')->ignore($category->id)
            ],
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category->update($validated);

        return back()->with('success', 'Category updated successfully.');
    }

    /**
     * Remove the specified category.
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);

            // Check if category has related entities
            if ($category->entities()->exists()) {
                return back()->withErrors([
                    'error' => 'Cannot delete category with existing entities.'
                ]);
            }

            $category->delete();

            return back()->with('success', 'Category deleted successfully.');
        } catch (\Exception $e) {
            \Log::error('Delete category failed: ' . $e->getMessage());

            return back()->withErrors([
                'error' => 'Failed to delete category: ' . $e->getMessage()
            ]);
        }
    }
}
