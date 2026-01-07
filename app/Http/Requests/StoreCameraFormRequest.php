<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCameraFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'entities' => ['required', 'array', 'min:1'],

            'entities.*.entity_id' => ['required', 'exists:entities,id'],
            'entities.*.rating_id' => ['nullable', 'exists:ratings,id'],
            'entities.*.note' => ['nullable', 'string', 'max:65535'],

            // ✅ NEW: optional image per entity row
            'entities.*.image' => ['nullable', 'image', 'max:5120'], // 5MB
        ];
    }

    public function messages(): array
    {
        return [
            'entities.required' => 'At least one entity must be provided.',
            'entities.min' => 'At least one entity must be filled.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasFilledEntity = false;

            foreach ($this->entities ?? [] as $entity) {
                $hasRating = !empty($entity['rating_id']);
                $hasNote   = !empty($entity['note']);
                // file() works with nested arrays too
                $hasImage  = $this->file('entities') !== null;

                // We'll check image per index below more accurately
            }

            // Accurate check: rating OR note OR image must exist for at least one entity
            foreach (($this->entities ?? []) as $i => $entity) {
                $hasRating = !empty($entity['rating_id']);
                $hasNote   = !empty($entity['note']);
                $hasImage  = $this->file("entities.$i.image") !== null;

                if ($hasRating || $hasNote || $hasImage) {
                    $hasFilledEntity = true;
                    break;
                }
            }

            if (!$hasFilledEntity) {
                $validator->errors()->add('entities', 'At least one entity must have a rating, note, or image.');
            }
        });
    }
}
