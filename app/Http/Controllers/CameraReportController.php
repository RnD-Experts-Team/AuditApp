<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CameraReportController extends Controller
{
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
            'filters' => $request->only(['store_id', 'group', 'year', 'week', 'report_type']),
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

        if ($year && $week) {
            [$weekStart, $weekEnd] = $this->getWeekStartAndEnd($year, $week);
            $cameraForms = $cameraForms->whereBetween('audits.date', [$weekStart, $weekEnd]);
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

            // --- Scoring logic for BOTH columns ---
            $perDateScoresWithoutAuto = [];
            $perDateScoresWithAuto = [];
            $hasAnyWeeklyAutoFail = false;
            $totalDailyAutoFails = 0;

            if (isset($formsByStoreByDate[$storeId])) {
                foreach ($formsByStoreByDate[$storeId] as $dateStr => $forms) {
                    $pass = $fail = 0;
                    $hasWeeklyAutoFail = false;
                    $autoFailPresentInDaily = false;

                    foreach ($forms as $form) {
                        $label = strtolower($form->rating_label ?? '');
                        if ($label === 'pass') $pass++;
                        if ($label === 'fail') $fail++;
                        if ($label === 'auto fail') {
                            if ($form->date_range_type === "weekly") {
                                $hasWeeklyAutoFail = true;
                            }
                            if ($form->date_range_type === "daily") {
                                $autoFailPresentInDaily = true;
                                $totalDailyAutoFails++; // count every daily auto fail
                            }
                        }
                    }
                    if ($hasWeeklyAutoFail) $hasAnyWeeklyAutoFail = true;

                    // Score WITHOUT auto fail
                    $denom = $pass + $fail;
                    $scoreWithoutAuto = ($denom > 0) ? $pass / $denom : null;
                    $perDateScoresWithoutAuto[] = $scoreWithoutAuto;

                    // Score WITH auto fail rules
                    if ($hasWeeklyAutoFail) {
                        $perDateScoresWithAuto[] = 0;
                    } elseif ($autoFailPresentInDaily) {
                        $perDateScoresWithAuto[] = 0;
                    } else {
                        $perDateScoresWithAuto[] = ($denom > 0) ? $pass / $denom : null;
                    }
                }
            }

            // Final store score logic
            $valsWithoutAuto = array_filter($perDateScoresWithoutAuto, fn($v) => is_numeric($v));
            $finalScoreWithoutAuto = count($valsWithoutAuto) ? round(array_sum($valsWithoutAuto) / count($valsWithoutAuto), 2) : null;

            $finalScoreWithAuto = null;
            if ($hasAnyWeeklyAutoFail) {
                $finalScoreWithAuto = 0;
            } elseif ($totalDailyAutoFails >= 3) {
                $finalScoreWithAuto = 0;
            } else {
                $vals = array_filter($perDateScoresWithAuto, fn($v) => is_numeric($v));
                $finalScoreWithAuto = count($vals) ? round(array_sum($vals) / count($vals), 2) : null;
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
