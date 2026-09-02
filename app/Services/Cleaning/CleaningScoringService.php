<?php

namespace App\Services\Cleaning;

use App\Models\CleaningSetting;

/**
 * All the numbers. Three of them, and they answer three different questions:
 *
 *   itemScore()   — of the inspection items that were actually graded, how much passed?
 *   chartScore()   — of the chart weight actually in play, how much passed?
 *   finalScore()   — the single number on the WhatsApp report: the weighted
 *                    average of the two above (50/50 by default)
 *
 * All three are returned to one decimal place. That is not cosmetic: the client
 * checks the arithmetic by hand off the screen, and (25 + 67) / 2 = 46 while the
 * true answer is 45.9. Showing the rounded inputs would make a correct system
 * look broken.
 *
 * ── What "in play" means (this is the important part) ──
 * A cell is scored only when it holds a real judgement. Two states leave BOTH
 * the numerator and the denominator:
 *
 *   not_applicable  the auditor decided it does not apply this period
 *   null / empty    nobody has graded it yet
 *
 * Ungraded cells used to sit in the denominator, which meant "the auditor forgot"
 * scored exactly like "the store failed" — the store was punished for someone
 * else's unfinished work, silently. That penalty is gone. What replaces it is
 * `is_complete`: an evaluation with any ungraded cell cannot be finalized, so a
 * partially graded grid can never reach a store. The pressure now lands on the
 * auditor instead of the store.
 *
 * ── auto_fail belongs to inspection items only ──
 * An auto_fail zeroes the ITEM half and nothing else. It never zeroes the final
 * score: one cell should not wipe out a store's whole result, whatever the
 * reason — the chart half stands on its own and the average reflects both.
 *
 * Cleaning TASKS have no auto_fail state at all. They used to accept one, but it
 * was arithmetically identical to `fail` (in the denominator, out of the
 * numerator), so it was removed rather than kept as a state that did nothing.
 */
class CleaningScoringService
{
    /**
     * Values that carry a real judgement and therefore belong in a score.
     *
     * The two lists are DIFFERENT and must stay separate. An inspection item can
     * be auto-failed; a cleaning task cannot — it has no such state. Sharing one
     * list and dropping 'auto_fail' from it would silently reclassify every
     * auto-failed ITEM as ungraded, which would pull it out of the item score and
     * block finalize. That is the trap this split exists to prevent.
     */
    private const GRADED_ITEM  = ['pass', 'fail', 'auto_fail'];
    private const GRADED_CHART = ['pass', 'fail'];

    /**
     * Weighted item score.
     *
     * @param  array<array{value:string, weight:int}>  $cells
     * @return array{pct:int, earned:int, total:int, graded:int, ungraded:int,
     *               not_applicable:int, missing:array<int, mixed>}
     */
    public function itemScore(array $cells): array
    {
        $earned = 0;
        $total  = 0;
        $graded = 0;
        $ungradedWeight = 0;
        $naWeight = 0;
        $missing = [];
        $hasAutoFail = false;

        foreach ($cells as $cell) {
            $value  = $cell['value'] ?? 'empty';
            $weight = max(0, (int) ($cell['weight'] ?? 1));

            if ($value === 'not_applicable') {
                $naWeight += $weight;
                continue;
            }

            if (!in_array($value, self::GRADED_ITEM, true)) {   // empty / null / unknown
                $ungradedWeight += $weight;
                $missing[] = $cell['ref'] ?? null;
                continue;
            }

            $graded++;
            $total += $weight;

            if ($value === 'pass') {
                $earned += $weight;
            }
            if ($value === 'auto_fail') {
                $hasAutoFail = true;
            }
        }

        // One auto_fail zeroes the item score, whatever the weights say.
        $pct = $hasAutoFail
            ? 0.0
            : ($total > 0 ? round($earned / $total * 100, 1) : 0.0);

        return [
            'pct'            => $pct,
            'has_auto_fail'  => $hasAutoFail,
            'earned'         => $earned,
            'total'          => $total,
            'graded'         => $graded,
            'ungraded'       => $ungradedWeight,
            'not_applicable' => $naWeight,
            'missing'        => array_values(array_filter($missing)),
        ];
    }

    /**
     * Weighted chart score. `weight` here must already be the EFFECTIVE weight
     * (base + anything allocated onto the task) — see EvaluationService.
     *
     * @param  array<array{verdict:?string, weight:int}>  $tasks
     * @return array{pct:int, earned:int, total:int, lost:int, graded:int,
     *               ungraded:int, not_applicable:int, missing:array<int, mixed>}
     */
    public function chartScore(array $tasks): array
    {
        $earned = 0;
        $total  = 0;
        $graded = 0;
        $ungradedWeight = 0;
        $naWeight = 0;
        $missing = [];

        foreach ($tasks as $task) {
            $verdict = $task['verdict'] ?? null;
            $weight  = max(0, (int) ($task['weight'] ?? 0));

            if ($verdict === 'not_applicable') {
                $naWeight += $weight;
                continue;
            }

            // A task has no auto_fail state. Any legacy value is treated as
            // ungraded here, and the migration rewrote the real ones to `fail`.
            if (!in_array((string) $verdict, self::GRADED_CHART, true)) {   // null / empty
                $ungradedWeight += $weight;
                $missing[] = $task['ref'] ?? null;
                continue;
            }

            $graded++;
            $total += $weight;

            if ($verdict === 'pass') {
                $earned += $weight;
            }
        }

        return [
            'pct'            => $total > 0 ? round($earned / $total * 100, 1) : 0.0,
            'earned'         => $earned,
            'total'          => $total,
            'lost'           => $total - $earned,
            'graded'         => $graded,
            'ungraded'       => $ungradedWeight,
            'not_applicable' => $naWeight,
            'missing'        => array_values(array_filter($missing)),
        ];
    }

    /**
     * The number on the report.
     *
     * ── Default: the weighted average of the two sides ──
     *
     *     final = (item_score × items_share) + (chart_score × chart_share)
     *
     * with the shares at 50/50, i.e. a plain arithmetic mean. Both shares live in
     * `cleaning_settings`, so the balance can be retuned without a code change.
     *
     * ── Why this replaced the old formula ──
     * The old one collapsed the WHOLE cleaning chart into a single all-or-nothing
     * point worth 1, next to inspection items worth their full weight. Two
     * consequences made it untenable:
     *
     *   - a store that failed EVERY cleaning task still scored ~95%, because it
     *     lost one point out of twenty-one. In a product called Cleaning Chart.
     *   - chart task weights were collected from the auditor and then ignored:
     *     a weight-2 task and a weight-50 task both simply broke the point.
     *
     * Averaging makes the chart half the score and makes its weights count.
     *
     * ── One side missing is NOT a zero ──
     * A period with no chart tasks in play (none due, or all not_applicable) has
     * chart_score = 0 — but that 0 means "nothing to measure", not "failed
     * everything". Averaging it in would hand a spotless store ~45%. So when one
     * side has nothing graded, the other side IS the score.
     *
     * ── auto_fail ──
     * An auto_fail on an inspection item zeroes the ITEM half, and nothing more.
     * It does NOT zero the final score: one cell should not wipe out a store's
     * whole result, whatever the reason. The chart half stands on its own and the
     * average reflects both.
     *
     * (Cleaning TASKS have no auto_fail state at all — see GRADED_CHART.)
     *
     * @param  array{pct:float, earned:int, total:int, graded:int, has_auto_fail?:bool}  $items
     * @param  array{pct:float, graded:int}  $chart
     * @return array{pct:?float, formula:string, items_share:int, chart_share:int,
     *               sides:array<int,string>, commitment_pass:bool, item_has_auto_fail:bool}
     */
    public function finalScore(array $items, array $chart): array
    {
        $formula     = (string) CleaningSetting::get('score_formula');
        $itemsShare  = CleaningSetting::getInt('items_share');
        $chartShare  = CleaningSetting::getInt('chart_share');

        // Kept as information only — the report still shows whether every chart
        // task passed, it just no longer drives the number.
        $commitmentPass = ($chart['graded'] ?? 0) > 0 && (float) ($chart['pct'] ?? 0) >= 100;

        $hasItems = ($items['graded'] ?? 0) > 0;
        $hasChart = ($chart['graded'] ?? 0) > 0;

        $base = [
            'formula'             => $formula,
            'items_share'         => $itemsShare,
            'chart_share'         => $chartShare,
            'commitment_pass'     => $commitmentPass,
            // Informational only: it explains WHY the item half reads 0, so the
            // UI can say "auto fail" instead of leaving the reader guessing.
            // It no longer changes the final number.
            'item_has_auto_fail'  => (bool) ($items['has_auto_fail'] ?? false),
            'sides'               => array_values(array_filter([
                $hasItems ? 'items' : null,
                $hasChart ? 'chart' : null,
            ])),
        ];

        if ($formula === 'excel') {
            return array_merge($base, $this->excelFinalScore($items, $chart, $commitmentPass));
        }

        // Nothing graded at all — there is no score to report, and 0% would be a
        // lie about a store nobody has inspected yet.
        if (!$hasItems && !$hasChart) {
            return array_merge($base, ['pct' => null]);
        }

        // One side absent → the other side is the whole score.
        if (!$hasChart) {
            return array_merge($base, ['pct' => round((float) $items['pct'], 1)]);
        }
        if (!$hasItems) {
            return array_merge($base, ['pct' => round((float) $chart['pct'], 1)]);
        }

        // Shares that do not add up to 100 would silently scale every score, so
        // normalise rather than trusting the stored values.
        $sum = $itemsShare + $chartShare;
        if ($sum <= 0) {
            $itemsShare = $chartShare = 50;
            $sum = 100;
        }

        $pct = (((float) $items['pct'] * $itemsShare) + ((float) $chart['pct'] * $chartShare)) / $sum;

        return array_merge($base, ['pct' => round($pct, 1)]);
    }

    /**
     * The previous formula, kept behind `score_formula = 'excel'` so the change
     * is reversible from the settings table without a deploy.
     *
     * @return array{pct:float}
     */
    private function excelFinalScore(array $items, array $chart, bool $commitmentPass): array
    {
        $commitmentWeight = max(0, (int) config('cleaning_calendar.commitment_weight', 1));

        $earned = (int) $items['earned'] + ($commitmentPass ? $commitmentWeight : 0);
        $total  = (int) $items['total'] + $commitmentWeight;

        return ['pct' => $total > 0 ? round($earned / $total * 100, 1) : 0.0];
    }

    /**
     * Completeness — the auditor's progress, kept strictly separate from the
     * store's score. `finalize` refuses to run while this is not 100%.
     *
     * @param  array{graded:int, not_applicable:int, missing:array<int, mixed>}  $items
     * @param  array{graded:int, not_applicable:int, missing:array<int, mixed>}  $chart
     * @param  int  $requiredItems  item cells expected this period
     * @param  int  $requiredTasks  chart tasks in play this period
     * @return array{completion_pct:float, is_complete:bool, graded_count:int,
     *               required_count:int, missing:array<int, mixed>}
     */
    public function completeness(array $items, array $chart, int $requiredItems, int $requiredTasks): array
    {
        $missing = array_merge($items['missing'] ?? [], $chart['missing'] ?? []);

        // not_applicable counts as done — it is a recorded decision, not a gap.
        // Only genuinely ungraded cells land in $missing.
        $missingCount = count($missing);
        $required     = $requiredItems + $requiredTasks;
        $graded       = max(0, $required - $missingCount);

        return [
            'completion_pct' => $required > 0 ? round($graded / $required * 100, 1) : 100.0,
            'is_complete'    => $missingCount === 0,
            'graded_count'   => $graded,
            'required_count' => $required,
            'missing'        => array_values($missing),
        ];
    }
}
