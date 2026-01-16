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
     * XLSX columns must match the FRONTEND (visibleEntities) + includes Notes column and previous styling.
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

        // 4) Build XLSX (previous design + notes column) but only visibleEntities columns
        $tmpXlsxPath = storage_path('app/tmp_' . Str::random(16) . '.xlsx');
        $this->buildReportXlsxPreviousDesignWithNotes(
            $tmpXlsxPath,
            $summary,
            $visibleEntities,
            $categoryGroups,
            $scoreData
        );

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
     * EXACT same logic as frontend visibleEntities:
     * keep entity if ANY store has ANY rating_count with count > 0
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
                    if (is_numeric($count) && (int) $count > 0) {
                        return true;
                    }
                }
            }

            return false;
        })->values();
    }

    /**
     * Mirrors frontend categoryGroups idea:
     * group by category label, preserve insertion order.
     */
    private function computeCategoryGroups($visibleEntities): array
    {
        $visibleEntities = collect($visibleEntities)->values();

        $groups = [];
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

        return array_values($groups);
    }

    /**
     * Previous Excel layout + Notes column restored,
     * while keeping columns EXACTLY to frontend's visibleEntities.
     */
    private function buildReportXlsxPreviousDesignWithNotes(
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

        $rowCategory = 1;
        $rowEntity   = 2;
        $rowSub      = 3;
        $rowData     = 4;

        // Store header merged vertically
        $sheet->setCellValue('A1', 'Store');
        $sheet->mergeCells('A1:A3');

        $currentColIndex = 2; // B

        $palette = [
            '8DB4E2', // #8DB4E2
            '92D050', // #92D050
            'FABF8F', // #FABF8F
            'D9D9D9', // #D9D9D9
            'A6A6A6', // #A6A6A6
            'EBE04F', // #EBE04F
            'DA9694', // #DA9694
        ];

        $catIdx = 0;
        $categoryRanges = [];

        // Entity columns (ONLY visible entities, grouped like frontend)
        foreach ($categoryGroups as $group) {
            $catStartCol = $currentColIndex;

            foreach ($group['entities'] as $entity) {
                $colLetter = Coordinate::stringFromColumnIndex($currentColIndex);

                $sheet->setCellValue($colLetter . $rowEntity, (string) $entity->entity_label);
                $sheet->setCellValue($colLetter . $rowSub, 'Ratings');

                $currentColIndex++;
            }

            $catEndCol = $currentColIndex - 1;

            if ($catEndCol >= $catStartCol) {
                $startLetter = Coordinate::stringFromColumnIndex($catStartCol);
                $endLetter   = Coordinate::stringFromColumnIndex($catEndCol);

                $sheet->setCellValue($startLetter . $rowCategory, (string) $group['label']);
                $sheet->mergeCells("{$startLetter}{$rowCategory}:{$endLetter}{$rowCategory}");

                $color = $palette[$catIdx % count($palette)];
                $catIdx++;

                $categoryRanges[] = [
                    'start' => $catStartCol,
                    'end'   => $catEndCol,
                    'color' => $color,
                ];
            }
        }

        // Score columns
        $scoreWithoutCol = $currentColIndex;
        $scoreWithCol    = $currentColIndex + 1;

        $swLetter = Coordinate::stringFromColumnIndex($scoreWithoutCol);
        $stLetter = Coordinate::stringFromColumnIndex($scoreWithCol);

        $sheet->setCellValue($swLetter . '1', 'Score Without Auto Fail');
        $sheet->mergeCells("{$swLetter}1:{$swLetter}3");

        $sheet->setCellValue($stLetter . '1', 'Total Score');
        $sheet->mergeCells("{$stLetter}1:{$stLetter}3");

        // Notes column AFTER Total Score (restored)
        $notesCol = $currentColIndex + 2;
        $notesLetter = Coordinate::stringFromColumnIndex($notesCol);

        $sheet->setCellValue($notesLetter . $rowCategory, 'Notes');
        $sheet->mergeCells("{$notesLetter}{$rowCategory}:{$notesLetter}{$rowSub}");

        $lastHeaderCol = $notesCol;
        $lastHeaderLetter = Coordinate::stringFromColumnIndex($lastHeaderCol);

        // ---- Data rows
        $writeRow = $rowData;

        foreach ($summary as $storeSummary) {
            $sheet->setCellValue("A{$writeRow}", $storeSummary['store_name'] ?? '');

            // Ratings per visible entity (same data source as frontend)
            $col = 2; // B
            foreach ($categoryGroups as $group) {
                foreach ($group['entities'] as $entity) {
                    $entityId = $entity->id;
                    $entityData = $storeSummary['entities'][$entityId] ?? null;

                    $ratingText = '-';
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
                            // Your old excel used ; but you said you don't care — keeping ; from previous design
                            $ratingText = implode('; ', $parts);
                        }
                    }

                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $writeRow, $ratingText);
                    $col++;
                }
            }

            // Scores
            $sid = (string) ($storeSummary['store_id'] ?? '');
            $scoreWithoutAuto = $scoreData[$sid]['score_without_auto_fail'] ?? null;
            $scoreWithAuto    = $scoreData[$sid]['score_with_auto_fail'] ?? null;

            // Keep numeric; previous design often percent-formatted, but numeric stays correct either way.
            $sheet->setCellValue($swLetter . $writeRow, is_numeric($scoreWithoutAuto) ? (float) $scoreWithoutAuto : null);
            $sheet->setCellValue($stLetter . $writeRow, is_numeric($scoreWithAuto) ? (float) $scoreWithAuto : null);

            // Notes column restored (aggregated notes per visible entity)
            $notesParts = [];
            foreach ($categoryGroups as $group) {
                foreach ($group['entities'] as $entity) {
                    $entityId = $entity->id;
                    $entityData = $storeSummary['entities'][$entityId] ?? null;

                    $notes = $entityData['notes'] ?? [];
                    if (!is_array($notes)) $notes = [];

                    $notes = collect($notes)
                        ->filter(fn($n) => is_string($n) && trim($n) !== '')
                        ->map(fn($n) => preg_replace("/\r\n|\r|\n/", ' ', trim($n)))
                        ->values()
                        ->all();

                    if (count($notes) > 0) {
                        $notesParts[] = (string) $entity->entity_label . ': ' . implode(' | ', $notes);
                    }
                }
            }

            $notesText = count($notesParts) ? implode(', ', $notesParts) : '-';
            $sheet->setCellValue($notesLetter . $writeRow, $notesText);

            $writeRow++;
        }

        $lastDataRow = max($rowData, $writeRow - 1);

        // Center everything (except Notes)
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

        // Notes column: left/top + wrap (previous design)
        $sheet->getStyle("{$notesLetter}1:{$notesLetter}{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_TOP,
                'wrapText'   => true,
            ],
        ]);

        // Bold header
        $sheet->getStyle("A1:{$lastHeaderLetter}3")->getFont()->setBold(true);
        $sheet->getStyle("B1:{$lastHeaderLetter}1")->getFont()->setSize(12);

        // Header fills for Store + Score + Notes
        $sheet->getStyle('A1:A3')->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle('A1:A3')->getFill()->getStartColor()->setRGB('F3F4F6');

        $sheet->getStyle("{$swLetter}1:{$stLetter}3")->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle("{$swLetter}1:{$stLetter}3")->getFill()->getStartColor()->setRGB('F3F4F6');

        $sheet->getStyle("{$notesLetter}1:{$notesLetter}3")->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle("{$notesLetter}1:{$notesLetter}3")->getFill()->getStartColor()->setRGB('F3F4F6');

        // Category coloring across entity columns (headers + data)
        foreach ($categoryRanges as $r) {
            $startLetter = Coordinate::stringFromColumnIndex($r['start']);
            $endLetter   = Coordinate::stringFromColumnIndex($r['end']);
            $range = "{$startLetter}1:{$endLetter}{$lastDataRow}";

            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID);
            $sheet->getStyle($range)->getFill()->getStartColor()->setRGB($r['color']);
        }

        // ✅ Keep your previous percent format for score columns (only data rows)
        if ($lastDataRow >= $rowData) {
            $sheet->getStyle("{$swLetter}{$rowData}:{$stLetter}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
        }

        // Freeze headers
        $sheet->freezePane("B{$rowData}");

        // Widths
        $sheet->getColumnDimension('A')->setWidth(28);

        // entity widths (count visible entity cols)
        $col = 2;
        $totalEntityCols = 0;
        foreach ($categoryGroups as $g) $totalEntityCols += count($g['entities']);

        for ($i = 0; $i < $totalEntityCols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(18);
            $col++;
        }

        $sheet->getColumnDimension($swLetter)->setWidth(22);
        $sheet->getColumnDimension($stLetter)->setWidth(14);
        $sheet->getColumnDimension($notesLetter)->setWidth(60);

        // Row heights (previous design)
        for ($rr = $rowData; $rr <= $lastDataRow; $rr++) {
            $sheet->getRowDimension($rr)->setRowHeight(90);
        }

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
