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
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasFilledEntity = false;

            foreach ($this->entities ?? [] as $entity) {
                if (!empty($entity['rating_id']) || !empty($entity['note'])) {
                    $hasFilledEntity = true;
                    break;
                }
            }

            if (!$hasFilledEntity) {
                $validator->errors()->add('entities', 'At least one entity must have a rating or note.');
            }
        });
    }
}
