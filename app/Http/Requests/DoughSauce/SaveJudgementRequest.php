<?php

namespace App\Http\Requests\DoughSauce;

use App\Services\Cleaning\AccountingCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveJudgementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level authorization is handled by pizzasys via the auth middleware.
        // The "specialist only" rule is enforced in DsJudgementController::update.
        return true;
    }

    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date_format:Y-m-d'],

            // Nullable on purpose: the specialist may record one verdict now and
            // the other later. The frontend shows what is still missing.
            'stickers_compliance' => ['nullable', Rule::in(['yes', 'no'])],
            'dough_quality'       => ['nullable', Rule::in(['pass', 'fail'])],

            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $weekStart = $this->input('week_start');

            if (! $weekStart || $validator->errors()->has('week_start')) {
                return;
            }

            // The unique key is (store_id, week_start), so a week_start that is
            // not the real first day of an accounting week would quietly create a
            // second judgement for the same week under a different date.
            $calendar = app(AccountingCalendarService::class);

            try {
                $resolved = $calendar->resolveDate(Carbon::parse($weekStart));
            } catch (\Throwable $e) {
                $validator->errors()->add('week_start', 'Date falls outside the accounting calendar.');

                return;
            }

            if (! $resolved['from']->isSameDay(Carbon::parse($weekStart))) {
                $validator->errors()->add(
                    'week_start',
                    'week_start must be the first day of an accounting week (a Tuesday). '
                    . 'For this date that is ' . $resolved['from']->toDateString()
                    . '. Get valid values from GET /api/dough-sauce/periods.'
                );
            }
        });
    }
}
