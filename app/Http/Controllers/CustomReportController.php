<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomReport\StoreCustomReportRequest;
use App\Http\Requests\CustomReport\UpdateCustomReportRequest;
use App\Models\CustomReport;
use App\Models\Entity;
use Inertia\Inertia;

class CustomReportController extends Controller
{
    private function formatReport(CustomReport $report): array
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

        // return response()->json($reports);

        return Inertia::render('CustomReports/Index', [
            'reports' => $reports,
        ]);

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
            ->findOrFail($id);

        // return response()->json($this->formatReport($report));

        return Inertia::render('CustomReports/Show', [
            'report' => $this->formatReport($report),
        ]);

    }

    /**
     * Admin: Create form
     */
    public function create()
    {
        $entities = Entity::with('category')
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        // return response()->json($entities);

        return Inertia::render('CustomReports/Create', [
            'entities' => $entities,
        ]);

    }

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

        /*
        return response()->json([
            'message' => 'Custom report created successfully',
            'report' => $this->formatReport($report),
        ], 201);
        */
        return redirect()
            ->route('custom-reports.index')
            ->with('success', 'Custom report created successfully');

    }

    /**
     * Admin: Edit
     */
    public function edit($id)
    {
        $report = CustomReport::with('entities')->findOrFail($id);

        $entities = Entity::with('category')
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        /*
        return response()->json([
            'entities' => $entities,
            'report' => $report,
        ]);
        */

        return Inertia::render('CustomReports/Edit', [
            'report' => $report,
            'entities' => $entities,
            'selectedEntityIds' => $report->entities->pluck('id'),
        ]);

    }

    /**
     * Admin: Update
     */
    public function update(UpdateCustomReportRequest $request, $id)
    {
        $report = CustomReport::findOrFail($id);

        $validated = $request->validated();

        $report->update([
            'name' => $validated['name'],
        ]);

        $report->entities()->sync($validated['entity_ids']);

        /*
        return response()->json([
            'report' => $report,
            'message' => 'report updated sussefully',
        ]);
        */
        return redirect()
            ->route('custom-reports.index')
            ->with('success', 'Custom report updated successfully');

    }

    /**
     * Admin: Delete
     */
    public function destroy($id)
    {
        CustomReport::findOrFail($id)->delete();

        return redirect()
            ->route('custom-reports.index')
            ->with('success', 'Custom report deleted successfully');

    }
}
