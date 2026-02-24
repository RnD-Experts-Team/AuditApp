<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomReport\StoreCustomReportRequest;
use App\Http\Requests\CustomReport\UpdateCustomReportRequest;
use App\Models\CustomReport;
use App\Models\Entity;

class CustomReportController extends Controller
{
    private function formatReport($report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'entities_count' => $report->entities_count,

            'created_by' => [
                'id' => optional($report->creator)->id,
                'name' => optional($report->creator)->name,
            ],

            'entities' => $report->entities->map(function ($entity) {
                return [
                    'id' => $entity->id,
                    'label' => $entity->entity_label,
                    'date_range_type' => $entity->date_range_type,
                    'report_type' => $entity->report_type,
                    'active' => $entity->active,

                    'category' => [
                        'id' => optional($entity->category)->id,
                        'label' => optional($entity->category)->label,
                    ],
                ];
            })->values(),
        ];
    }

    /**
     * Admin and Audit: List all reports
     */
    public function index()
    {
        $reports = CustomReport::withCount('entities')
            ->with([
                'creator:id,name',
                'entities.category:id,label',
            ])
            ->latest()
            ->get()
            ->map(fn ($report) => $this->formatReport($report))
            ->values();

        return response()->json($reports, 200);

    }

    /**
     * Admin and Audit: Show single report
     */
    public function show($id)
    {
        $report = CustomReport::withCount('entities')
            ->with([
                'creator:id,name',
                'entities.category:id,label',
            ])
            ->find($id);
        if (! $report) {
            return response()->json([
                'success' => false,
                'message' => 'Custom Report not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($report),
            'message' => 'Custom Report retrieved successfully',
        ], 200);

    }

    // /**
    //  * Admin: Create form
    //  */
    // public function create()
    // {
    //     $entities = Entity::with('category')
    //         ->orderBy('category_id')
    //         ->orderBy('entity_label')
    //         ->get();

    //     return response()->json($entities);

    // }

    /**
     * Admin: Store
     */
    public function store(StoreCustomReportRequest $request)
    {
        $validated = $request->validated();

        $report = CustomReport::create([
            'name' => $validated['name'],
            'created_by' => auth()->id(),
        ]);

        $report->entities()->sync($validated['entity_ids']);

        $report->load(['creator:id,name', 'entities.category:id,label'])
            ->loadCount('entities');

        return response()->json([
            'success' => true,
            'message' => 'Custom report created successfully',
            'data' => $this->formatReport($report),
        ], 201);
    }

    /**
     * Admin: Edit
     */
    // public function edit($id)
    // {
    //     $report = CustomReport::with('entities')->findOrFail($id);

    //     $entities = Entity::with('category')
    //         ->orderBy('category_id')
    //         ->orderBy('entity_label')
    //         ->get();

    //     return response()->json([
    //         'entities' => $entities,
    //         'report' => $report,
    //     ]);

    // }

    /**
     * Admin: Update
     */
    public function update(UpdateCustomReportRequest $request, $id)
    {
        $report = CustomReport::find($id);

        if (! $report) {
            return response()->json([
                'success' => false,
                'message' => 'Custom Report not found',
            ], 404);
        }

        $validated = $request->validated();

        $report->update([
            'name' => $validated['name'],
        ]);

        $report->entities()->sync($validated['entity_ids']);

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($report),
            'message' => 'report updated successfully',
        ]);
    }

    /**
     * Admin: Delete
     */
    public function destroy($id)
    {
        $report = CustomReport::find($id);
        if (! $report) {
            return response()->json([
                'success' => false,
                'message' => 'Custom Report not found',
            ], 404);
        }
        $report->delete();

        return response()->json([
            'success' => true,
            'data' => $this->formatReport($report),
            'message' => 'report deleted successfully',
        ]);

    }
}
