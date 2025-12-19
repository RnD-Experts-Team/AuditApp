<?php

namespace App\Services;

class ScoringService
{
    /**
     * Check if any form has Auto Fail rating
     *
     * @param array $forms Array of form objects with rating_label
     * @return bool
     */
    public function hasAutoFail(array $forms): bool
    {
        foreach ($forms as $form) {
            $label = strtolower($form->rating_label ?? '');
            if ($label === 'auto fail') {
                return true;
            }
        }
        return false;
    }

    /**
     * Count days with at least one Auto Fail rating
     * Each day with one or more Auto Fails counts as one occurrence
     *
     * @param array $formsByDate Array indexed by date, each containing array of forms
     * @return int
     */
    public function countAutoFailDays(array $formsByDate): int
    {
        $autoFailDays = 0;
        
        foreach ($formsByDate as $date => $forms) {
            if ($this->hasAutoFail($forms)) {
                $autoFailDays++;
            }
        }
        
        return $autoFailDays;
    }

    /**
     * Calculate daily score for a specific date
     * Returns 0 if any Auto Fail exists, otherwise pass/(pass+fail)
     * Returns null if no pass/fail data
     *
     * @param array $forms Array of form objects for a single day
     * @return float|null
     */
    public function calculateDailyScore(array $forms): ?float
    {
        // Check for Auto Fail first
        if ($this->hasAutoFail($forms)) {
            return 0.0;
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
        
        // If no pass/fail data, return null
        if ($total === 0) {
            return null;
        }

        return $pass / $total;
    }

    /**
     * Calculate weekly score considering Auto Fail rules
     * Returns 0 if weekly Auto Fail or 3+ daily Auto Fail days
     * Otherwise returns average of daily scores
     *
     * @param array $formsByDate Array indexed by date, each containing array of forms
     * @param bool $hasWeeklyAutoFail Whether any weekly audit has Auto Fail
     * @return float|null
     */
    public function calculateWeeklyScore(array $formsByDate, bool $hasWeeklyAutoFail): ?float
    {
        // Rule 1: If any weekly audit has Auto Fail, score is 0
        if ($hasWeeklyAutoFail) {
            return 0.0;
        }

        // Rule 2: Count daily Auto Fail days
        $autoFailDays = $this->countAutoFailDays($formsByDate);
        if ($autoFailDays >= 3) {
            return 0.0;
        }

        // Calculate average of daily scores
        $dailyScores = [];
        foreach ($formsByDate as $date => $forms) {
            $dailyScore = $this->calculateDailyScore($forms);
            if ($dailyScore !== null) {
                $dailyScores[] = $dailyScore;
            }
        }

        if (empty($dailyScores)) {
            return null;
        }

        return array_sum($dailyScores) / count($dailyScores);
    }
}
