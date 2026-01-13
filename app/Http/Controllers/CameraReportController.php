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
use ZipArchive;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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
     * Export ZIP containing XLSX + attachments.
     * XLSX columns must match the FRONTEND TABLE exactly:
     * Store + visibleEntities (frontend logic) + 2 score columns.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = Auth::user();

        // 1) Build report data the same way as index
        $reportData = $this->getReportData($request, $user);

        $summary   = $reportData['summary'];
        $entities  = collect($reportData['entities']);
        $scoreData = $reportData['scoreData'];

        // 2) Compute EXACT same visible entities as frontend
        $visibleEntities = $this->computeVisibleEntities($entities, $summary);

        // 3) Compute category groups from visible entities (same idea as frontend)
        $categoryGroups = $this->computeCategoryGroups($visibleEntities);

        $reportType = (string) $request->input('report_type', '');
        $storeId    = (string) $request->input('store_id', '');
        $group      = (string) $request->input('group', '');
        $dateFrom   = (string) $request->input('date_from', '');
        $dateTo     = (string) $request->input('date_to', '');
        $ratingId   = (string) $request->input('rating_id', '');

        $timestamp = now()->format('Y-m-d');

        $parts = [];
        if ($reportType !== '') $parts[] = "Type-{$reportType}";
        if ($storeId !== '')    $parts[] = "Store-{$storeId}";
        if ($group !== '')      $parts[] = "Group-{$group}";
        if ($ratingId !== '')   $parts[] = "Rating-{$ratingId}";
        if ($dateFrom !== '' || $dateTo !== '') $parts[] = "{$dateFrom}_to_{$dateTo}";

        $baseName = 'camera-report' . (count($parts) ? '-' . implode('_', $parts) : '') . "_{$timestamp}";
        $xlsxName = "{$baseName}.xlsx";
        $zipName  = "{$baseName}.zip";

        // 4) Build XLSX that matches frontend columns exactly
        $tmpXlsxPath = storage_path('app/tmp_' . Str::random(16) . '.xlsx');
        $this->buildFrontendExactXlsx($tmpXlsxPath, $summary, $visibleEntities, $categoryGroups, $scoreData);

        // 5) Collect attachments
        $attachments = $this->getReportAttachments($request, $user);

        // 6) Zip it
        $tmpZipPath = storage_path('app/tmp_' . Str::random(16) . '.zip');

        $zip = new ZipArchive();
        if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpXlsxPath);
            throw new \RuntimeException("Failed to create ZIP");
        }

        $zip->addFile($tmpXlsxPath, $xlsxName);

        foreach ($attachments as $att) {
            $storeSlug = Str::slug($att->store_name ?: ('store-' . $att->store_id));
            $zipStoreFolder = "stores/{$att->store_id}-{$storeSlug}";
            $zipDateFolder  = "{$zipStoreFolder}/{$att->date}";

            $relativePathInDisk = $att->path;
            if (!$relativePathInDisk) continue;
            if (!Storage::disk('public')->exists($relativePathInDisk)) continue;

            $fileContents = Storage::disk('public')->get($relativePathInDisk);
            $filename = basename($relativePathInDisk);

            $zip->addFromString("{$zipDateFolder}/{$filename}", $fileContents);
        }

        $zip->close();
        @unlink($tmpXlsxPath);

        return new StreamedResponse(function () use ($tmpZipPath) {
            $out = fopen('php://output', 'w');
            $in  = fopen($tmpZipPath, 'r');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            @unlink($tmpZipPath);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $zipName . '"',
        ]);
    }

    /**
     * EXACT same logic as frontend visibleEntities useMemo:
     * - keep entity if ANY store has ANY rating_count with count > 0
     */
    private function computeVisibleEntities($entities, array $summary)
    {
        $entities = collect($entities)->values();

        if (count($summary) === 0) {
            return collect([]);
        }

        return $entities->filter(function ($entity) use ($summary) {
            $entityId = $entity->id;

            foreach ($summary as $storeSummary) {
                if (!isset($storeSummary['entities'][$entityId])) continue;

                $entityData = $storeSummary['entities'][$entityId];
                $ratingCounts = $entityData['rating_counts'] ?? [];

                if (!is_array($ratingCounts) || count($ratingCounts) === 0) {
                    continue;
                }

                foreach ($ratingCounts as $rc) {
                    $count = $rc['count'] ?? 0;
                    if (is_numeric($count) && (int)$count > 0) {
                        return true;
                    }
                }
            }

            return false;
        })->values();
    }

    /**
     * Mirrors frontend categoryGroups:
     * - group by category label
     * - preserve insertion order (first seen order)
     */
    private function computeCategoryGroups($visibleEntities): array
    {
        $visibleEntities = collect($visibleEntities)->values();

        $groups = []; // ['Label' => ['label'=>..., 'entities'=>[Entity...]]]

        foreach ($visibleEntities as $entity) {
            $label = $entity->category->label ?? 'Uncategorized';

            if (!isset($groups[$label])) {
                $groups[$label] = [
                    'label' => $label,
                    'entities' => [],
                ];
            }

            $groups[$label]['entities'][] = $entity;
        }

        // Return in insertion order as numeric array
        return array_values($groups);
    }

    /**
     * XLSX builder that matches the FRONTEND table columns exactly:
     * - Store
     * - visible entity columns grouped by categoryGroups
     * - Score Without Auto Fail
     * - Total Score
     *
     * No Notes column (frontend doesn’t show notes in the table).
     */
    private function buildFrontendExactXlsx(
        string $outputPath,
        array $summary,
        $visibleEntities,
        array $categoryGroups,
        array $scoreData
    ): void {
        $visibleEntities = collect($visibleEntities)->values();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Camera Report');

        $rowCat  = 1;
        $rowEnt  = 2;
        $rowData = 3;

        // Store header
        $sheet->setCellValue('A1', 'Store');
        $sheet->mergeCells('A1:A2');

        $currentCol = 2; // B

        // Category header + entity header
        foreach ($categoryGroups as $group) {
            $startCol = $currentCol;

            foreach ($group['entities'] as $entity) {
                $colLetter = Coordinate::stringFromColumnIndex($currentCol);
                $sheet->setCellValue($colLetter . $rowEnt, (string) $entity->entity_label);
                $currentCol++;
            }

            $endCol = $currentCol - 1;

            if ($endCol >= $startCol) {
                $startLetter = Coordinate::stringFromColumnIndex($startCol);
                $endLetter   = Coordinate::stringFromColumnIndex($endCol);

                $sheet->setCellValue($startLetter . $rowCat, (string) $group['label']);
                $sheet->mergeCells("{$startLetter}{$rowCat}:{$endLetter}{$rowCat}");
            }
        }

        // Score columns
        $scoreWithoutCol = $currentCol;
        $scoreWithCol    = $currentCol + 1;

        $swLetter = Coordinate::stringFromColumnIndex($scoreWithoutCol);
        $stLetter = Coordinate::stringFromColumnIndex($scoreWithCol);

        $sheet->setCellValue($swLetter . $rowCat, 'Score Without Auto Fail');
        $sheet->mergeCells("{$swLetter}{$rowCat}:{$swLetter}{$rowEnt}");

        $sheet->setCellValue($stLetter . $rowCat, 'Total Score');
        $sheet->mergeCells("{$stLetter}{$rowCat}:{$stLetter}{$rowEnt}");

        $lastHeaderCol = $scoreWithCol;
        $lastHeaderLetter = Coordinate::stringFromColumnIndex($lastHeaderCol);

        // ---- Data rows
        $r = $rowData;

        foreach ($summary as $storeSummary) {
            $sheet->setCellValue("A{$r}", $storeSummary['store_name'] ?? '');

            $col = 2; // B

            // ONLY visible entities, in the same order the frontend renders them (categoryGroups order)
            foreach ($categoryGroups as $group) {
                foreach ($group['entities'] as $entity) {
                    $entityId = $entity->id;

                    $entityData = $storeSummary['entities'][$entityId] ?? null;

                    $cellText = '-';

                    if ($entityData && isset($entityData['rating_counts']) && is_array($entityData['rating_counts'])) {
                        $parts = [];
                        foreach ($entityData['rating_counts'] as $rc) {
                            $count = $rc['count'] ?? 0;
                            if (is_numeric($count) && (int)$count > 0) {
                                $label = $rc['rating_label'] ?? 'No Rating';
                                $parts[] = ((int)$count) . ' ' . ($label ?: 'No Rating');
                            }
                        }
                        if (count($parts) > 0) {
                            // frontend joins with ", "
                            $cellText = implode(', ', $parts);
                        }
                    }

                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $r, $cellText);
                    $col++;
                }
            }

            // Scores (as raw number like frontend shows)
            $sid = (string) ($storeSummary['store_id'] ?? '');

            $scoreWithoutAuto = $scoreData[$sid]['score_without_auto_fail'] ?? null;
            $scoreWithAuto    = $scoreData[$sid]['score_with_auto_fail'] ?? null;

            $sheet->setCellValue($swLetter . $r, is_numeric($scoreWithoutAuto) ? $scoreWithoutAuto : null);
            $sheet->setCellValue($stLetter . $r, is_numeric($scoreWithAuto) ? $scoreWithAuto : null);

            $r++;
        }

        $lastDataRow = max($rowData, $r - 1);

        // Styling (not important for matching, but keeps it readable)
        $sheet->getStyle("A1:{$lastHeaderLetter}{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Header styles
        $sheet->getStyle("A1:{$lastHeaderLetter}2")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastHeaderLetter}2")->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle("A1:{$lastHeaderLetter}2")->getFill()->getStartColor()->setRGB('F3F4F6');

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(28);

        $col = 2;
        $totalEntityCols = 0;
        foreach ($categoryGroups as $g) $totalEntityCols += count($g['entities']);

        for ($i = 0; $i < $totalEntityCols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(18);
            $col++;
        }

        $sheet->getColumnDimension($swLetter)->setWidth(22);
        $sheet->getColumnDimension($stLetter)->setWidth(14);

        // Freeze like a typical table
        $sheet->freezePane("B{$rowData}");

        // Save
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
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

        /**
         * 1) Base query for ratings/scoring rows (NO notes join to avoid duplication)
         */
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
                'ratings.label as rating_label'
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
         * - BUT keep ALL rows (all ratings) for those stores
         */
        $eligibleStoreIds = null;
        if ($ratingId !== null) {
            $eligibleStoreIds = (clone $cameraFormsBase)
                ->where('camera_forms.rating_id', $ratingId)
                ->distinct()
                ->pluck('store_id')
                ->values()
                ->all();

            $cameraFormsBase->whereIn('stores.id', $eligibleStoreIds ?: [-1]);
        }

        $cameraForms = $cameraFormsBase->get();

        /**
         * 2) Entities list (for frontend)
         */
        $entitiesQuery = Entity::with('category');
        if ($reportType) {
            $entitiesQuery->where('report_type', $reportType);
        }
        $entities = $entitiesQuery
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        /**
         * 3) Filtered stores
         */
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

        /**
         * 4) Notes query
         */
        $notesBase = DB::table('camera_form_notes')
            ->join('camera_forms', 'camera_forms.id', '=', 'camera_form_notes.camera_form_id')
            ->join('audits', 'camera_forms.audit_id', '=', 'audits.id')
            ->join('stores', 'audits.store_id', '=', 'stores.id')
            ->join('entities', 'camera_forms.entity_id', '=', 'entities.id')
            ->select(
                'stores.id as store_id',
                'audits.date',
                'entities.id as entity_id',
                'camera_form_notes.note as note'
            )
            ->when(!$user->isAdmin(), fn($q) => $q->whereIn('stores.group', $userGroups))
            ->when($storeId, fn($q) => $q->where('stores.id', $storeId))
            ->when($group, fn($q) => $q->where('stores.group', $group))
            ->when($reportType, fn($q) => $q->where('entities.report_type', $reportType));

        if ($dateFrom) $notesBase->where('audits.date', '>=', $dateFrom);
        if ($dateTo)   $notesBase->where('audits.date', '<=', $dateTo);

        if ($ratingId !== null) {
            $notesBase->whereIn('stores.id', $eligibleStoreIds ?: [-1]);
        }

        $notesRows = $notesBase->get();

        $notesByStoreEntity = [];
        foreach ($notesRows as $r) {
            if (is_string($r->note) && trim($r->note) !== '') {
                $notesByStoreEntity[$r->store_id][$r->entity_id][] = trim($r->note);
            }
        }

        /**
         * 5) Group forms by store + date for scoring
         */
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
                $forms = $cameraForms
                    ->where('store_id', $sid)
                    ->where('entity_id', $entity->id);

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
                    'entity_id'     => $entity->id,
                    'entity_label'  => $entity->entity_label,
                    'rating_counts' => $ratingCounts,
                    'notes'         => $notesByStoreEntity[$sid][$entity->id] ?? [],
                    'category'      => $entity->category ? $entity->category->toArray() : null,
                ];
            }

            /**
             * 6) Scoring logic (unchanged)
             */
            $perDateScoresWithoutAuto = [];
            $hasAnyWeeklyAutoFail = false;

            if (isset($formsByStoreByDate[$sid])) {
                foreach ($formsByStoreByDate[$sid] as $dateStr => $formsForDate) {
                    $pass = $fail = 0;

                    foreach ($formsForDate as $form) {
                        $label = strtolower($form->rating_label ?? '');
                        if ($label === 'pass') $pass++;
                        if ($label === 'fail') $fail++;
                    }

                    $denom = $pass + $fail;
                    $scoreWithoutAuto = ($denom > 0) ? $pass / $denom : null;
                    $perDateScoresWithoutAuto[] = $scoreWithoutAuto;

                    foreach ($formsForDate as $form) {
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

    private function getReportAttachments(Request $request, $user)
    {
        $storeId    = $request->input('store_id');
        $group      = $request->input('group');
        $reportType = $request->input('report_type');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $ratingId   = $request->input('rating_id');
        $ratingId = ($ratingId !== null && $ratingId !== '') ? (int) $ratingId : null;

        $userGroups = $user->isAdmin() ? null : $user->getGroupNumbers();

        $q = DB::table('camera_form_note_attachments as a')
            ->join('camera_form_notes as n', 'n.id', '=', 'a.camera_form_note_id')
            ->join('camera_forms as cf', 'cf.id', '=', 'n.camera_form_id')
            ->join('audits', 'audits.id', '=', 'cf.audit_id')
            ->join('stores', 'stores.id', '=', 'audits.store_id')
            ->join('entities', 'entities.id', '=', 'cf.entity_id')
            ->leftJoin('ratings', 'ratings.id', '=', 'cf.rating_id')
            ->select(
                'stores.id as store_id',
                'stores.store as store_name',
                'stores.group as store_group',
                'audits.date as date',
                'a.path as path'
            )
            ->when(!$user->isAdmin(), fn($qq) => $qq->whereIn('stores.group', $userGroups))
            ->when($storeId, fn($qq) => $qq->where('stores.id', $storeId))
            ->when($group, fn($qq) => $qq->where('stores.group', $group))
            ->when($reportType, fn($qq) => $qq->where('entities.report_type', $reportType));

        if ($dateFrom) $q->where('audits.date', '>=', $dateFrom);
        if ($dateTo)   $q->where('audits.date', '<=', $dateTo);

        if ($ratingId !== null) {
            $eligibleStoreIds = (clone $q)
                ->where('cf.rating_id', $ratingId)
                ->distinct()
                ->pluck('store_id')
                ->values()
                ->all();

            $q->whereIn('stores.id', $eligibleStoreIds ?: [-1]);
        }

        return $q->get();
    }
}
