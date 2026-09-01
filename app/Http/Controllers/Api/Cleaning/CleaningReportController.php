<?php

namespace App\Http\Controllers\Api\Cleaning;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Cleaning\EvaluationService;
use App\Services\Cleaning\PeriodKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CleaningReportController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluations)
    {
    }

    /**
     * JSON with the SAME numbers as the grid — the frontend renders it to a PNG
     * for WhatsApp. One source of truth (EvaluationService).
     *
     * `final_score` and `commitment_pass` now come from here. The client used to
     * compute the final score itself in JavaScript, which meant item weights
     * could never reach the report (the formula counted items instead of
     * weighing them) and the number could never be stored or charted over time.
     */
    public function data(Request $request): JsonResponse
    {
        [$type, $key] = $this->validatePeriod($request);

        return response()->json(
            $this->evaluations->buildGrid($type, $key, $this->allowedStoreIds($request))
        );
    }

    /**
     * CSV of the whole grid. Weights shown are EFFECTIVE weights (base + any
     * allocated onto the task), and anything the store is not being scored on is
     * spelled out rather than silently dropped — otherwise the task list changes
     * from week to week with no explanation on the store's side.
     */
    public function csv(Request $request): StreamedResponse
    {
        [$type, $key] = $this->validatePeriod($request);
        $grid = $this->evaluations->buildGrid($type, $key, $this->allowedStoreIds($request));

        $items = collect($grid['items']);
        $freqs = ['daily', 'weekly', 'monthly', 'hourly'];

        $header = array_merge(
            ['Store'],
            $items->map(fn ($i) => ($i['weight'] ?? 1) > 1
                ? $i['name'] . ' (x' . $i['weight'] . ')'
                : $i['name'])->all(),
            ['Item Score'],
            array_map(fn ($f) => 'CC ' . ucfirst($f), $freqs),
            ['Chart Score', 'Commitment', 'Final Score', 'Completion', 'Not Due This Period', 'Weight Moved']
        );

        $filename = "cleaning_evaluation_{$key}.csv";

        return response()->streamDownload(function () use ($grid, $items, $freqs, $header) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);

            foreach ($grid['rows'] as $row) {
                $line = [$row['store']];

                foreach ($items as $item) {
                    $line[] = $this->itemLabel($row['item_values'][$item['name']]['value'] ?? 'empty');
                }
                $line[] = $this->pct($row['item_score']);

                foreach ($freqs as $f) {
                    $tasks = $row['chart'][$f] ?? [];
                    $line[] = implode(' | ', array_map(
                        fn ($t) => $t['name'] . ':' . $this->verdictLabel($t)
                            . '(' . ($t['verdict'] === 'not_applicable' ? 0 : $t['effective_weight']) . ')',
                        $tasks
                    )) ?: '-';
                }

                $line[] = $this->pct($row['chart_score']);
                $line[] = $row['commitment_pass'] ? 'Pass' : 'Fail';
                $line[] = $this->pct($row['final_score']);
                $line[] = $row['completion_pct'] . '%' . ($row['is_complete'] ? '' : ' (incomplete)');

                // Why this store has fewer tasks than last week.
                $line[] = implode(' | ', array_map(
                    fn ($a) => $a['name'] . ' (' . $a['weight'] . ')',
                    $row['absent_tasks'] ?? []
                )) ?: '-';

                // Where any moved weight went.
                $line[] = $this->allocationNote($row) ?: '-';

                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── helpers ──

    /**
     * One decimal, and a dash rather than "0%" when nothing has been graded —
     * an uninspected store has no score, which is not the same as scoring zero.
     */
    private function pct(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 1) . '%';
    }

    /**
     * `not_applicable` prints as N/A, never as its raw value — its weight left
     * the denominator, so showing a number here would read as weight lost.
     */
    private function itemLabel(string $value): string
    {
        return match ($value) {
            'not_applicable' => 'N/A',
            'auto_fail'      => 'auto_fail',
            'empty'          => 'empty',
            default          => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function verdictLabel(array $task): string
    {
        return match ($task['verdict']) {
            null             => 'ungraded',
            'not_applicable' => 'N/A',
            default          => (string) $task['verdict'],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function allocationNote(array $row): string
    {
        $names = [];
        foreach (($row['chart'] ?? []) as $tasks) {
            foreach ($tasks as $task) {
                foreach (($task['allocated_from'] ?? []) as $source) {
                    $names[] = $task['name'] . ' +' . $source['amount'] . ' (from ' . $source['name'] . ')';
                }
            }
        }

        return implode(' | ', $names);
    }

    /** @return array{0:string,1:string} */
    private function validatePeriod(Request $request): array
    {
        $data = $request->validate(PeriodKeyService::validationRules());

        return [$data['period_type'] ?? 'week', $data['period_key']];
    }

    /** @return int[] */
    private function allowedStoreIds(Request $request): array
    {
        $user = $request->user();
        return $user ? $user->allowedStoreIdsCached() : Store::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
