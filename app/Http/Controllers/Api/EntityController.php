<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EntityController extends Controller
{
    /**
     * GET /api/entities
     * List entities and categories for management
     */
    public function index()
    {
        $categories = Category::withCount('entities')
            ->orderByRaw('sort_order IS NULL, sort_order ASC')
            ->orderBy('label')
            ->get();

        $entities = Entity::with('category')
            ->leftJoin('categories', 'entities.category_id', '=', 'categories.id')
            ->select('entities.*')
            ->orderByRaw('categories.sort_order IS NULL, categories.sort_order ASC')
            ->orderByRaw('entities.sort_order IS NULL, entities.sort_order ASC')
            ->orderBy('entities.entity_label')
            ->get();

        return $this->success('Entities fetched successfully', [
            'entities'   => $entities,
            'categories' => $categories,
        ]);
    }

    /**
     * POST /api/entities
     * Create new entity
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'entity_label'    => 'required|string|max:255',
            'category_id'     => 'nullable|exists:categories,id',
            'date_range_type' => 'required|in:daily,weekly',
            'report_type'     => 'nullable|in:main,secondary',
            'sort_order'      => 'nullable|integer|min:0',
            'active'          => 'required|boolean',
        ]);

        $entity = Entity::create($validated);

        return $this->success('Entity created successfully', $entity, 201);
    }

    /**
     * PUT /api/entities/{id}
     * Update entity
     */
    public function update(Request $request, int $id)
    {
        $entity = Entity::findOrFail($id);

        $validated = $request->validate([
            'entity_label'    => 'required|string|max:255',
            'category_id'     => 'nullable|exists:categories,id',
            'date_range_type' => 'required|in:daily,weekly',
            'report_type'     => 'nullable|in:main,secondary',
            'sort_order'      => 'nullable|integer|min:0',
            'active'          => 'required|boolean',
        ]);

        $entity->update($validated);

        return $this->success('Entity updated successfully', $entity);
    }

    /**
     * DELETE /api/entities/{id}
     * Delete entity if unused
     */
    public function destroy(int $id)
    {
        try {
            $entity = Entity::findOrFail($id);

            if ($entity->cameraForms()->exists()) {
                return $this->error(
                    'Cannot delete entity with existing camera forms.',
                    422
                );
            }

            $entity->delete();

            return $this->success('Entity deleted successfully');
        } catch (\Throwable $e) {
            Log::error('Delete entity failed: ' . $e->getMessage());

            return $this->error(
                'Failed to delete entity',
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
