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
     * Backend CSV export (includes notes, respects filters)
     */
    public function export(Request $request): StreamedResponse
    {
        $user = Auth::user();

        // 1) Build report data
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

        $baseName = 'camera-report' . (count($parts) ? '-' . implode('_', $parts) : '') . "_{$timestamp}";
        $xlsxName = "{$baseName}.xlsx";
        $zipName  = "{$baseName}.zip";

        // 2) Build XLSX file on disk
        $tmpXlsxPath = storage_path('app/tmp_' . Str::random(16) . '.xlsx');
        $this->buildReportXlsx($tmpXlsxPath, $summary, $entities, $scoreData);

        // 3) Collect attachments
        $attachments = $this->getReportAttachments($request, $user);

        // 4) Build ZIP on disk then stream it
        $tmpZipPath = storage_path('app/tmp_' . Str::random(16) . '.zip');

        $zip = new ZipArchive();
        if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpXlsxPath);
            throw new \RuntimeException("Failed to create ZIP");
        }

        // Add XLSX to root
        $zip->addFile($tmpXlsxPath, $xlsxName);

        // Add images: stores/<store>/<date>/<filename>
        foreach ($attachments as $att) {
            $storeSlug = Str::slug($att->store_name ?: ('store-' . $att->store_id));
            $zipStoreFolder = "stores/{$att->store_id}-{$storeSlug}";
            $zipDateFolder  = "{$zipStoreFolder}/{$att->date}";

            $relativePathInDisk = $att->path; // public disk relative path
            if (!$relativePathInDisk) continue;

            if (!Storage::disk('public')->exists($relativePathInDisk)) continue;

            $fileContents = Storage::disk('public')->get($relativePathInDisk);
            $filename = basename($relativePathInDisk);

            $zip->addFromString("{$zipDateFolder}/{$filename}", $fileContents);
        }

        $zip->close();

        // Cleanup temp XLSX now that it’s inside zip
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
         * -----------------------------
         * 1) Base query for ratings/scoring rows (NO notes join to avoid duplication)
         * -----------------------------
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
         * Rating filter behavior (same as your original):
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
         * -----------------------------
         * 2) Entities list (for columns)
         * -----------------------------
         */
        $entitiesQuery = Entity::with('category');
        if ($reportType) {
            $entitiesQuery->where('report_type', $reportType);
        }
        $entities = $entitiesQuery->orderBy('category_id')->orderBy('entity_label')->get();

        /**
         * -----------------------------
         * 3) Filtered stores for display
         * -----------------------------
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
         * -----------------------------
         * 4) Notes query (NEW schema)
         *    We fetch notes separately and group by store+entity
         * -----------------------------
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

        // notesByStoreEntity[store_id][entity_id] = [note, note, ...]
        $notesByStoreEntity = [];
        foreach ($notesRows as $r) {
            if (is_string($r->note) && trim($r->note) !== '') {
                $notesByStoreEntity[$r->store_id][$r->entity_id][] = trim($r->note);
            }
        }

        /**
         * -----------------------------
         * 5) Group forms by store + date for scoring
         * -----------------------------
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
             * -----------------------------
             * 6) Scoring logic (unchanged)
             * -----------------------------
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

        // Base: attachments -> notes -> camera_forms -> audits -> stores -> entities
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

        /**
         * Same rating filter behavior you had:
         * include only stores that have at least one row rating_id = X
         * but keep all rows for those stores
         */
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

    private function buildReportXlsx(string $outputPath, $summary, $entities, array $scoreData): void
    {
        $entities = collect($entities)->sortBy(function ($e) {
            $catOrder = $e->category->sort_order ?? 999999;
            $entOrder = $e->sort_order ?? 999999;
            return sprintf('%06d-%06d-%s', $catOrder, $entOrder, (string) $e->entity_label);
        })->values();

        $entitiesByCategory = $entities->groupBy(function ($e) {
            return $e->category->label ?? 'Uncategorized';
        });

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Camera Report');

        $rowCategory = 1;
        $rowEntity   = 2;
        $rowSub      = 3;
        $rowData     = 4;

        // A header merged vertically (Store only)
        $sheet->setCellValue('A1', 'Store');
        $sheet->mergeCells('A1:A3');

        $currentColIndex = 2; // B (since A is Store)

        $palette = [
            'D9E1F2',
            'E2EFDA',
            'FFF2CC',
            'FCE4D6',
            'E4DFEC',
            'DDEBF7',
            'F8CBAD',
            'C6E0B4',
            'FFD966',
            'D0CECE'
        ];
        $catIdx = 0;
        $categoryRanges = [];

        // 1 column per entity = Ratings only
        foreach ($entitiesByCategory as $categoryLabel => $catEntities) {
            $catStartCol = $currentColIndex;

            foreach ($catEntities as $entity) {
                $col = $currentColIndex;
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);

                $sheet->setCellValue($colLetter . $rowEntity, (string) $entity->entity_label);
                $sheet->setCellValue($colLetter . $rowSub, 'Ratings');

                $currentColIndex += 1;
            }

            $catEndCol = $currentColIndex - 1;

            $startLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($catStartCol);
            $endLetter   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($catEndCol);

            $sheet->setCellValue($startLetter . $rowCategory, (string) $categoryLabel);
            $sheet->mergeCells("{$startLetter}{$rowCategory}:{$endLetter}{$rowCategory}");

            $color = $palette[$catIdx % count($palette)];
            $catIdx++;

            $categoryRanges[] = [
                'start' => $catStartCol,
                'end'   => $catEndCol,
                'color' => $color,
            ];
        }

        // Score columns (after entity columns)
        $scoreWithoutCol = $currentColIndex;
        $scoreWithCol    = $currentColIndex + 1;

        $swLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($scoreWithoutCol);
        $stLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($scoreWithCol);

        $sheet->setCellValue($swLetter . '1', 'Score Without Auto Fail');
        $sheet->mergeCells("{$swLetter}1:{$swLetter}3");

        $sheet->setCellValue($stLetter . '1', 'Total Score');
        $sheet->mergeCells("{$stLetter}1:{$stLetter}3");

        // Notes column AFTER Total Score
        $notesCol = $currentColIndex + 2;
        $notesLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($notesCol);

        $sheet->setCellValue($notesLetter . $rowCategory, 'Notes');
        $sheet->mergeCells("{$notesLetter}{$rowCategory}:{$notesLetter}{$rowSub}");

        $lastHeaderCol = $notesCol;
        $lastHeaderLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastHeaderCol);

        // ---- Data rows
        $writeRow = $rowData;

        foreach ($summary as $storeSummary) {
            $sheet->setCellValue("A{$writeRow}", $storeSummary['store_name'] ?? '');

            // Ratings per entity
            $col = 2; // B
            foreach ($entities as $entity) {
                $entityData = $storeSummary['entities'][$entity->id] ?? null;

                $ratingText = '-';
                if ($entityData) {
                    $ratingText = collect($entityData['rating_counts'] ?? [])
                        ->filter(fn($rc) => ($rc['count'] ?? 0) > 0)
                        ->map(fn($rc) => ($rc['count'] ?? 0) . ' ' . ($rc['rating_label'] ?? 'No Rating'))
                        ->implode('; ');
                    if ($ratingText === '') $ratingText = '-';
                }

                $ratingCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $writeRow;
                $sheet->setCellValue($ratingCell, $ratingText);

                $col += 1;
            }

            // Scores (write numeric fraction for percent formatting)
            $sid = (string) ($storeSummary['store_id'] ?? '');
            $scoreWithoutAuto = $scoreData[$sid]['score_without_auto_fail'] ?? null;
            $scoreWithAuto    = $scoreData[$sid]['score_with_auto_fail'] ?? null;

            $sheet->setCellValue($swLetter . $writeRow, is_numeric($scoreWithoutAuto) ? (float)$scoreWithoutAuto : null);
            $sheet->setCellValue($stLetter . $writeRow, is_numeric($scoreWithAuto) ? (float)$scoreWithAuto : null);

            // Notes single cell at the end
            $notesParts = [];
            foreach ($entities as $entity) {
                $entityData = $storeSummary['entities'][$entity->id] ?? null;
                $notes = $entityData['notes'] ?? [];

                $notes = collect($notes)
                    ->filter(fn($n) => is_string($n) && trim($n) !== '')
                    ->map(fn($n) => preg_replace("/\r\n|\r|\n/", ' ', trim($n)))
                    ->values()
                    ->all();

                if (count($notes) > 0) {
                    $notesParts[] = (string) $entity->entity_label . ': ' . implode(' | ', $notes);
                }
            }

            $notesText = count($notesParts) ? implode(', ', $notesParts) : '-';
            $sheet->setCellValue($notesLetter . $writeRow, $notesText);

            $writeRow++;
        }

        $lastDataRow = max($rowData, $writeRow - 1);

        /**
         * Center everything (except Notes)
         */
        $sheet->getStyle("A1:{$lastHeaderLetter}{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
            ],
        ]);

        // Notes column: not centered (left/top + wrap)
        $sheet->getStyle("{$notesLetter}1:{$notesLetter}{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                'wrapText'   => true,
            ],
        ]);

        // Bold header
        $sheet->getStyle("A1:{$lastHeaderLetter}3")->getFont()->setBold(true);
        $sheet->getStyle("B1:{$lastHeaderLetter}1")->getFont()->setSize(12);

        // Header fills
        $sheet->getStyle('A1:A3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('A1:A3')->getFill()->getStartColor()->setRGB('F3F4F6');

        $sheet->getStyle("{$swLetter}1:{$stLetter}3")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle("{$swLetter}1:{$stLetter}3")->getFill()->getStartColor()->setRGB('F3F4F6');

        $sheet->getStyle("{$notesLetter}1:{$notesLetter}3")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle("{$notesLetter}1:{$notesLetter}3")->getFill()->getStartColor()->setRGB('F3F4F6');

        // Category coloring across entity rating columns (headers + data)
        foreach ($categoryRanges as $r) {
            $startLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($r['start']);
            $endLetter   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($r['end']);
            $range = "{$startLetter}1:{$endLetter}{$lastDataRow}";

            $sheet->getStyle($range)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sheet->getStyle($range)->getFill()->getStartColor()->setRGB($r['color']);
        }

        /**
         * ✅ Format scores as percentage ONLY for data rows (after row 3)
         * (Rows 1-3 are headers; leave them untouched)
         */
        if ($lastDataRow >= $rowData) {
            $sheet->getStyle("{$swLetter}{$rowData}:{$stLetter}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
        }

        // Freeze headers
        $sheet->freezePane("B{$rowData}");

        // Widths
        $sheet->getColumnDimension('A')->setWidth(28);

        $col = 2;
        foreach ($entities as $entity) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setWidth(18);
            $col++;
        }

        $sheet->getColumnDimension($swLetter)->setWidth(22);
        $sheet->getColumnDimension($stLetter)->setWidth(14);
        $sheet->getColumnDimension($notesLetter)->setWidth(60);

        /**
         * ✅ Row height:
         * - Rows 1..3 unchanged
         * - Rows 4..lastDataRow forced to 90
         */
        for ($r = $rowData; $r <= $lastDataRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(90);
        }

        // Save
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($outputPath);
    }
}
