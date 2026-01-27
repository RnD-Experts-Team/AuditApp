<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    /**
     * POST /api/categories
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'      => 'required|string|max:255|unique:categories,label',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category = Category::create($validated);

        return $this->success('Category created successfully', $category, 201);
    }

    /**
     * PUT /api/categories/{id}
     */
    public function update(Request $request, int $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'label')->ignore($category->id),
            ],
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category->update($validated);

        return $this->success('Category updated successfully', $category);
    }

    /**
     * DELETE /api/categories/{id}
     */
    public function destroy(int $id)
    {
        try {
            $category = Category::findOrFail($id);

            if ($category->entities()->exists()) {
                return $this->error(
                    'Cannot delete category with existing entities.',
                    422
                );
            }

            $category->delete();

            return $this->success('Category deleted successfully');
        } catch (\Throwable $e) {
            Log::error('Delete category failed: ' . $e->getMessage());

            return $this->error(
                'Failed to delete category',
                500
            );
        }
    }

    /* ------------------------------------------------------------
     | API helpers
     |------------------------------------------------------------ */

    private function success(string $message, $data = null, int $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $code);
    }

    private function error(string $message, int $code)
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'data'    => null,
            'errors'  => null,
        ], $code);
    }
}
