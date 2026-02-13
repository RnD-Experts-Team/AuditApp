<?php

namespace App\Services;

class ScoringService
{
    // Treat these rating labels as "zero-score triggers"
    private array $zeroScoreLabels = ['auto fail', 'urgent'];

    private function isZeroScoreLabel(?string $label): bool
    {
        $label = strtolower(trim($label ?? ''));

        return in_array($label, $this->zeroScoreLabels, true);
    }

    /**
     * Count total occurrences of urgent/auto fail across the whole week (all dates).
     */
    public function countZeroScoreOccurrences(array $formsByDate): int
    {
        $count = 0;

        foreach ($formsByDate as $date => $forms) {
            foreach ($forms as $form) {
                if ($this->isZeroScoreLabel($form->rating_label ?? null)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Calculate daily score for a specific date
     * Returns 0 if ANY urgent/auto fail exists that day
     * Otherwise pass/(pass+fail)
     */
    public function calculateDailyScore(array $forms): ?float
    {
        foreach ($forms as $form) {
            if ($this->isZeroScoreLabel($form->rating_label ?? null)) {
                return 0.0;
            }
        }

        $pass = 0;
        $fail = 0;

        foreach ($forms as $form) {
            $label = strtolower($form->rating_label ?? '');
            if ($label === 'pass') {
                $pass++;
            } elseif ($label === 'fail') {
                $fail++;
            }
        }

        $total = $pass + $fail;
        if ($total === 0) {
            return null;
        }

        return $pass / $total;
    }

    /**
     * Weekly score:
     * - If any WEEKLY audit has urgent/auto fail => 0
     * - Else if total urgent/auto fail occurrences >= 3 => 0
     * - Else average of daily scores
     */
    public function calculateWeeklyScore(array $formsByDate, bool $hasWeeklyZeroScore): ?float
    {

        if ($hasWeeklyZeroScore) {
            return 0.0;
        }
        // Weekly rule:
        // If total urgent + auto fail across the week >= 3 → 0
        if ($this->countZeroScoreOccurrences($formsByDate) >= 3) {
            return 0.0;
        }

        // Otherwise average daily scores
        $dailyScores = [];

        foreach ($formsByDate as $date => $forms) {
            $daily = $this->calculateDailyScore($forms);

            if ($daily !== null) {
                $dailyScores[] = $daily;
            }
        }

        if (empty($dailyScores)) {
            return null;
        }

        return array_sum($dailyScores) / count($dailyScores);
    }
}
