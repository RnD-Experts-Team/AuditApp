<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCameraFormRequest extends FormRequest
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

            // ✅ NEW
            'entities.*.image' => ['nullable', 'image', 'max:5120'], // 5MB
            'entities.*.remove_image' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasFilledEntity = false;

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
