<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Entity;
use App\Services\ScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Auth;

class CameraReportController extends Controller
{
    protected ScoringService $scoringService;

    public function __construct(ScoringService $scoringService)
    {
        $this->scoringService = $scoringService;
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        // Stores & groups based on role
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

        // Ratings for dropdown filter
        $ratings = DB::table('ratings')
            ->select('id', 'label')
            ->orderBy('id')
            ->get();

        $reportData = $this->getReportData($request, $user);

        return Inertia::render('CameraReports/Index', [
            'reportData' => $reportData,
            'stores'     => $stores,
            'groups'     => $groups,
            'ratings'    => $ratings,
            'filters'    => $request->only(['store_id', 'group', 'report_type', 'date_from', 'date_to', 'rating_id']),
        ]);
    }

    /**
     * Backend CSV export (includes notes, respects filters)
     */
    public function export(Request $request): StreamedResponse
    {
        $user = Auth::user();
        $reportData = $this->getReportData($request, $user);

        $summary   = $reportData['summary'];
        $entities  = $reportData['entities'];
        $scoreData = $reportData['scoreData'];

        $reportType = (string) $request->input('report_type', '');
        $storeId    = (string) $request->input('store_id', '');
        $group      = (string) $request->input('group', '');
        $dateFrom   = (string) $request->input('date_from', '');
        $dateTo     = (string) $request->input('date_to', '');
        $ratingId   = (string) $request->input('rating_id', '');

        $timestamp = now()->format('Y-m-d');
        $parts = [];
        if ($reportType !== '') $parts[] = "Type-{$reportType}";
        if ($storeId !== '') $parts[] = "Store-{$storeId}";
        if ($group !== '') $parts[] = "Group-{$group}";
        if ($ratingId !== '') $parts[] = "Rating-{$ratingId}";
        if ($dateFrom !== '' || $dateTo !== '') $parts[] = "{$dateFrom}_to_{$dateTo}";

        $filename = 'camera-report' . (count($parts) ? '-' . implode('_', $parts) : '') . "_{$timestamp}.csv";

        $response = new StreamedResponse(function () use ($summary, $entities, $scoreData) {
            $out = fopen('php://output', 'w');

            // Header
            $header = ['Store', 'Group'];
            foreach ($entities as $entity) {
                $header[] = $entity->entity_label . ' (Ratings)';
                $header[] = $entity->entity_label . ' (Notes)';
            }
            $header[] = 'Score Without Auto Fail';
            $header[] = 'Total Score';

            fputcsv($out, $header);

            foreach ($summary as $storeSummary) {
                $row = [
                    $storeSummary['store_name'],
                    $storeSummary['store_group'] ?? '',
                ];

                foreach ($entities as $entity) {
                    $entityData = $storeSummary['entities'][$entity->id] ?? null;

                    // Ratings summary
                    $ratingText = '';
                    if ($entityData) {
                        $ratingText = collect($entityData['rating_counts'] ?? [])
                            ->filter(fn($rc) => ($rc['count'] ?? 0) > 0)
                            ->map(fn($rc) => ($rc['count'] ?? 0) . ' ' . ($rc['rating_label'] ?? 'No Rating'))
                            ->implode('; ');
                    }

                    // Notes
                    $notesText = '';
                    if ($entityData) {
                        $notesText = collect($entityData['notes'] ?? [])
                            ->filter(fn($n) => is_string($n) && trim($n) !== '')
                            ->map(fn($n) => preg_replace("/\r\n|\r|\n/", ' ', trim($n)))
                            ->implode(' | ');
                    }

                    $row[] = $ratingText !== '' ? $ratingText : '-';
                    $row[] = $notesText !== '' ? $notesText : '-';
                }

                $sid = (string) $storeSummary['store_id'];
                $scoreWithoutAuto = $scoreData[$sid]['score_without_auto_fail'] ?? null;
                $scoreWithAuto    = $scoreData[$sid]['score_with_auto_fail'] ?? null;

                $row[] = is_numeric($scoreWithoutAuto) ? $scoreWithoutAuto : '-';
                $row[] = is_numeric($scoreWithAuto) ? $scoreWithAuto : '-';

                fputcsv($out, $row);
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function getReportData(Request $request, $user): array
    {
        $storeId    = $request->input('store_id');
        $group      = $request->input('group');
        $reportType = $request->input('report_type');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $ratingId   = $request->input('rating_id');

        $ratingId = ($ratingId !== null && $ratingId !== '') ? (int) $ratingId : null;

        $userGroups = $user->isAdmin() ? null : $user->getGroupNumbers();

        // Base query for camera forms (WITHOUT rating filter by default)
        $cameraFormsBase = DB::table('camera_forms')
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
                'ratings.label as rating_label',
                'camera_forms.note as note'
            )
            ->when(!$user->isAdmin(), fn($q) => $q->whereIn('stores.group', $userGroups))
            ->when($storeId, fn($q) => $q->where('stores.id', $storeId))
            ->when($group, fn($q) => $q->where('stores.group', $group))
            ->when($reportType, fn($q) => $q->where('entities.report_type', $reportType));

        if ($dateFrom) $cameraFormsBase->where('audits.date', '>=', $dateFrom);
        if ($dateTo)   $cameraFormsBase->where('audits.date', '<=', $dateTo);

        /**
         * Rating filter behavior:
         * - Only include STORES that have at least one row with rating_id = X
         * - BUT keep ALL rows (all ratings) for those stores in the report/export
         */
        $eligibleStoreIds = null;
        if ($ratingId !== null) {
            // ✅ FIX: pluck store_id (the selected alias), not "id"
            $eligibleStoreIds = (clone $cameraFormsBase)
                ->where('camera_forms.rating_id', $ratingId)
                ->distinct()
                ->pluck('store_id')   // <-- was pluck('id') causing the crash
                ->values()
                ->all();

            // If none matched, force empty result sets
            $cameraFormsBase->whereIn('stores.id', $eligibleStoreIds ?: [-1]);
        }

        $cameraForms = $cameraFormsBase->get();

        $entitiesQuery = Entity::with('category');
        if ($reportType) {
            $entitiesQuery->where('report_type', $reportType);
        }
        $entities = $entitiesQuery->orderBy('category_id')->orderBy('entity_label')->get();

        // Filtered stores for display
        $storesQuery = Store::query();

        if (!$user->isAdmin()) {
            $storesQuery->whereIn('group', $userGroups);
        }
        if ($storeId) $storesQuery->where('id', $storeId);
        if ($group)   $storesQuery->where('group', $group);

        if ($ratingId !== null) {
            $storesQuery->whereIn('id', $eligibleStoreIds ?: [-1]);
        }

        $filteredStores = $storesQuery->orderBy('store')->get();

        // Group forms by store + date
        $formsByStoreByDate = [];
        foreach ($cameraForms as $f) {
            $formsByStoreByDate[$f->store_id][$f->date][] = $f;
        }

        $summary = [];
        $scoreData = [];

        foreach ($filteredStores as $store) {
            $sid = $store->id;

            $entitiesSummary = [];
            foreach ($entities as $entity) {
                $forms = $cameraForms->where('store_id', $sid)->where('entity_id', $entity->id);

                $counts = [];
                $notes = [];

                foreach ($forms as $form) {
                    $label = $form->rating_label ?? 'No Rating';
                    $counts[$label] = ($counts[$label] ?? 0) + 1;

                    if (is_string($form->note) && trim($form->note) !== '') {
                        $notes[] = trim($form->note);
                    }
                }

                $ratingCounts = [];
                foreach ($counts as $label => $count) {
                    $ratingCounts[] = [
                        'rating_label' => $label,
                        'count' => $count,
                    ];
                }

                $entitiesSummary[$entity->id] = [
                    'entity_id'     => $entity->id,
                    'entity_label'  => $entity->entity_label,
                    'rating_counts' => $ratingCounts,
                    'notes'         => $notes,
                    'category'      => $entity->category ? $entity->category->toArray() : null,
                ];
            }

            // --- Scoring logic ---
            $perDateScoresWithoutAuto = [];
            $hasAnyWeeklyAutoFail = false;

            if (isset($formsByStoreByDate[$sid])) {
                foreach ($formsByStoreByDate[$sid] as $dateStr => $forms) {
                    $pass = $fail = 0;

                    foreach ($forms as $form) {
                        $label = strtolower($form->rating_label ?? '');
                        if ($label === 'pass') $pass++;
                        if ($label === 'fail') $fail++;
                    }

                    $denom = $pass + $fail;
                    $scoreWithoutAuto = ($denom > 0) ? $pass / $denom : null;
                    $perDateScoresWithoutAuto[] = $scoreWithoutAuto;

                    foreach ($forms as $form) {
                        if (
                            strtolower($form->rating_label ?? '') === 'auto fail'
                            && $form->date_range_type === 'weekly'
                        ) {
                            $hasAnyWeeklyAutoFail = true;
                            break;
                        }
                    }
                }
            }

            $valsWithoutAuto = array_filter($perDateScoresWithoutAuto, fn($v) => is_numeric($v));
            $finalScoreWithoutAuto = count($valsWithoutAuto)
                ? round(array_sum($valsWithoutAuto) / count($valsWithoutAuto), 2)
                : null;

            $finalScoreWithAuto = null;
            if (isset($formsByStoreByDate[$sid])) {
                $weeklyScore = $this->scoringService->calculateWeeklyScore(
                    $formsByStoreByDate[$sid],
                    $hasAnyWeeklyAutoFail
                );
                $finalScoreWithAuto = $weeklyScore !== null ? round($weeklyScore, 2) : null;
            }

            $scoreData[(string) $sid] = [
                'score_without_auto_fail' => $finalScoreWithoutAuto,
                'score_with_auto_fail'    => $finalScoreWithAuto,
            ];

            $summary[] = [
                'store_id'    => $sid,
                'store_name'  => $store->store,
                'store_group' => $store->group,
                'entities'    => $entitiesSummary,
            ];
        }

        return [
            'summary'      => $summary,
            'entities'     => $entities,
            'total_stores' => count($summary),
            'scoreData'    => $scoreData,
        ];
    }
}
