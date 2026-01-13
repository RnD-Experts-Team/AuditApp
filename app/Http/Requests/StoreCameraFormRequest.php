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

            // notes array (optional)
            'entities.*.notes' => ['nullable', 'array'],

            'entities.*.notes.*.note' => ['nullable', 'string', 'max:65535'],
            'entities.*.notes.*.images' => ['nullable', 'array'],
            'entities.*.notes.*.images.*' => ['nullable', 'image', 'max:5120'],
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

            foreach (($this->entities ?? []) as $i => $entity) {
                $hasRating = !empty($entity['rating_id']);

                $notes = $entity['notes'] ?? [];
                $hasNotesOrImages = false;

                if (is_array($notes)) {
                    foreach ($notes as $j => $noteRow) {
                        $noteText = isset($noteRow['note']) ? trim((string)$noteRow['note']) : '';
                        $files = $this->file("entities.$i.notes.$j.images") ?? [];
                        $hasImages = is_array($files) && count($files) > 0;

                        if ($noteText !== '' || $hasImages) {
                            $hasNotesOrImages = true;
                            break;
                        }
                    }
                }

                if ($hasRating || $hasNotesOrImages) {
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
