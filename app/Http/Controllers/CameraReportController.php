<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Entity;
use App\Services\ScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CameraReportController extends Controller
{
    protected $scoringService;

    public function __construct(ScoringService $scoringService)
    {
        $this->scoringService = $scoringService;
    }
    public function index(Request $request)
    {
        $user = auth()->user();

        // Get stores and groups based on user role
        if ($user->isAdmin()) {
            $stores = Store::select('id', 'store', 'group')->orderBy('store')->get();
            $groups = Store::select('group')->distinct()->whereNotNull('group')->orderBy('group')->pluck('group');
        } else {
            $userGroups = $user->getGroupNumbers();
            $stores = Store::select('id', 'store', 'group')
                ->whereIn('group', $userGroups)
                ->orderBy('store')
                ->get();
            $groups = collect($userGroups)->sort()->values();
        }

        $reportData = $this->getReportData($request, $user);

        return Inertia::render('CameraReports/Index', [
            'reportData' => $reportData,
            'stores' => $stores,
            'groups' => $groups,
            'filters' => $request->only(['store_id', 'group', 'year', 'week', 'report_type', 'date_from', 'date_to']),
        ]);
    }

    private function getWeekStartAndEnd($year, $week)
    {
        // 2 = Tuesday, ISO weeks (Monday start by default)
        $dt = new \DateTime();
        $dt->setISODate($year, $week, 2); // Tuesday of the week
        $startOfWeek = $dt->format('Y-m-d');
        $dt->modify('+6 days');
        $endOfWeek = $dt->format('Y-m-d');
        return [$startOfWeek, $endOfWeek];
    }

    private function getReportData(Request $request, $user)
    {
        $year = $request->input('year');
        $week = $request->input('week');
        $storeId = $request->input('store_id');
        $group = $request->input('group');
        $reportType = $request->input('report_type');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Get user's groups for filtering
        $userGroups = $user->isAdmin() ? null : $user->getGroupNumbers();

        $cameraForms = DB::table('camera_forms')
            ->join('audits', 'camera_forms.audit_id', '=', 'audits.id')
            ->join('stores', 'audits.store_id', '=', 'stores.id')
            ->join('entities', 'camera_forms.entity_id', '=', 'entities.id')
            ->leftJoin('ratings', 'camera_forms.rating_id', '=', 'ratings.id')
            ->select(
                'stores.id as store_id',
                'stores.store as store_name',
                'stores.group as store_group',
                'audits.date',
                'entities.id as entity_id',
                'entities.entity_label',
                'entities.date_range_type',
                'camera_forms.rating_id',
                'ratings.label as rating_label'
            )
            // Filter by user groups if not admin
            ->when(!$user->isAdmin(), fn($q) => $q->whereIn('stores.group', $userGroups))
            ->when($storeId, fn($q) => $q->where('stores.id', $storeId))
            ->when($group, fn($q) => $q->where('stores.group', $group))
            ->when($reportType, fn($q) => $q->where('entities.report_type', $reportType));

        // Apply week filter
        if ($year && $week) {
            [$weekStart, $weekEnd] = $this->getWeekStartAndEnd($year, $week);
            $cameraForms = $cameraForms->whereBetween('audits.date', [$weekStart, $weekEnd]);
        }

        // Apply date range filter
        if ($dateFrom) {
            $cameraForms = $cameraForms->where('audits.date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $cameraForms = $cameraForms->where('audits.date', '<=', $dateTo);
        }

        $cameraForms = $cameraForms->get();

        $entitiesQuery = Entity::with('category');
        if ($reportType) {
            $entitiesQuery->where('report_type', $reportType);
        }
        $entities = $entitiesQuery->orderBy('category_id')->orderBy('entity_label')->get();

        // Get filtered stores for display
        $storesQuery = Store::query();
        // Filter by user groups if not admin
        if (!$user->isAdmin()) {
            $storesQuery->whereIn('group', $userGroups);
        }
        if ($storeId) $storesQuery->where('id', $storeId);
        if ($group) $storesQuery->where('group', $group);
        $filteredStores = $storesQuery->orderBy('store')->get();

        $formsByStoreByDate = [];
        foreach ($cameraForms as $f) {
            // Group camera forms by store, by date
            $formsByStoreByDate[$f->store_id][$f->date][] = $f;
        }

        $summary = [];
        $scoreData = [];
        foreach ($filteredStores as $store) {
            $storeId = $store->id;
            $entitiesSummary = [];
            foreach ($entities as $entity) {
                $forms = $cameraForms->where('store_id', $storeId)->where('entity_id', $entity->id);
                $counts = [];
                foreach ($forms as $form) {
                    $label = $form->rating_label ?? 'No Rating';
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
                $ratingCounts = [];
                foreach ($counts as $label => $count) {
                    $ratingCounts[] = [
                        'rating_label' => $label,
                        'count' => $count,
                    ];
                }
                $entitiesSummary[$entity->id] = [
                    'entity_id' => $entity->id,
                    'entity_label' => $entity->entity_label,
                    'rating_counts' => $ratingCounts,
                    'category' => $entity->category ? $entity->category->toArray() : null,
                ];
            }

            // --- Scoring logic using ScoringService ---
            $perDateScoresWithoutAuto = [];
            $hasAnyWeeklyAutoFail = false;

            if (isset($formsByStoreByDate[$storeId])) {
                foreach ($formsByStoreByDate[$storeId] as $dateStr => $forms) {
                    // Calculate score without Auto Fail consideration
                    $pass = $fail = 0;
                    foreach ($forms as $form) {
                        $label = strtolower($form->rating_label ?? '');
                        if ($label === 'pass') $pass++;
                        if ($label === 'fail') $fail++;
                    }
                    $denom = $pass + $fail;
                    $scoreWithoutAuto = ($denom > 0) ? $pass / $denom : null;
                    $perDateScoresWithoutAuto[] = $scoreWithoutAuto;

                    // Check for weekly Auto Fail
                    foreach ($forms as $form) {
                        if (strtolower($form->rating_label ?? '') === 'auto fail' && $form->date_range_type === 'weekly') {
                            $hasAnyWeeklyAutoFail = true;
                            break;
                        }
                    }
                }
            }

            // Calculate final scores
            $valsWithoutAuto = array_filter($perDateScoresWithoutAuto, fn($v) => is_numeric($v));
            $finalScoreWithoutAuto = count($valsWithoutAuto) ? round(array_sum($valsWithoutAuto) / count($valsWithoutAuto), 2) : null;

            // Calculate score with Auto Fail rules using ScoringService
            $finalScoreWithAuto = null;
            if (isset($formsByStoreByDate[$storeId])) {
                $weeklyScore = $this->scoringService->calculateWeeklyScore(
                    $formsByStoreByDate[$storeId],
                    $hasAnyWeeklyAutoFail
                );
                $finalScoreWithAuto = $weeklyScore !== null ? round($weeklyScore, 2) : null;
            }

            $scoreData[$storeId] = [
                'score_without_auto_fail' => $finalScoreWithoutAuto,
                'score_with_auto_fail' => $finalScoreWithAuto
            ];

            $summary[] = [
                'store_id' => $store->id,
                'store_name' => $store->store,
                'store_group' => $store->group,
                'entities' => $entitiesSummary,
            ];
        }

        return [
            'summary' => $summary,
            'entities' => $entities,
            'total_stores' => count($summary),
            'scoreData' => $scoreData,
        ];
    }
}
