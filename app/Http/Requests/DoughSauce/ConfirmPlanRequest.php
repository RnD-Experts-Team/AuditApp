<?php

namespace App\Http\Requests\DoughSauce;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level authorization is handled by pizzasys via the auth middleware.
        // "Is this user the MANAGER of this particular store" is a different
        // question, and the controller answers it — see DsPlanController::confirm.
        return true;
    }

    public function rules(): array
    {
        $keys = array_keys(config('dough_sauce.ingredients'));

        return [
            'plan_date' => ['required', 'date_format:Y-m-d'],

            // All three ingredients or none. A plan missing the sauce line is not
            // a partial plan, it is a bug in the client — and accepting it would
            // leave a store with two thirds of a target and no warning.
            'lines'                  => ['required', 'array', 'size:' . count($keys)],
            'lines.*.ingredient_key' => ['required', 'string', Rule::in($keys)],

            'lines.*.buffer_pct' => [
                'required',
                'numeric',
                'min:0',
                'max:' . config('dough_sauce.buffer_max_pct'),
            ],

            // gt:0 rather than min:0 — a confirmed plan of zero means "knead
            // nothing tonight", which no one intends to say through this screen.
            'lines.*.planned_qty' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.size'                  => 'A plan must contain exactly :size lines — one per ingredient.',
            'lines.*.ingredient_key.in'   => 'Unknown ingredient. Expected one of: '
                                             . implode(', ', array_keys(config('dough_sauce.ingredients'))) . '.',
            'lines.*.buffer_pct.max'      => 'Buffer may not exceed :max%.',
            'lines.*.planned_qty.gt'      => 'Planned quantity must be greater than zero.',
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $lines = (array) $this->input('lines', []);
            $keys  = array_filter(array_column($lines, 'ingredient_key'));

            // size:3 alone would accept the same ingredient three times.
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('lines', 'Each ingredient may appear only once.');
            }
        });
    }
}
