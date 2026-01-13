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

            // notes
            'entities.*.notes' => ['nullable', 'array'],
            'entities.*.notes.*.id' => ['nullable', 'integer'],
            'entities.*.notes.*.note' => ['nullable', 'string', 'max:65535'],

            'entities.*.notes.*.images' => ['nullable', 'array'],
            'entities.*.notes.*.images.*' => ['nullable', 'image', 'max:5120'],

            // remove attachments per note
            'entities.*.notes.*.remove_attachment_ids' => ['nullable', 'array'],
            'entities.*.notes.*.remove_attachment_ids.*' => ['integer'],

            // remove whole notes per entity
            'entities.*.remove_note_ids' => ['nullable', 'array'],
            'entities.*.remove_note_ids.*' => ['integer'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasFilledEntity = false;

            foreach (($this->entities ?? []) as $i => $entity) {
                $hasRating = !empty($entity['rating_id']);

                $removeNoteIds = $entity['remove_note_ids'] ?? [];
                $wantsRemoveNote = is_array($removeNoteIds) && count($removeNoteIds) > 0;

                $notes = $entity['notes'] ?? [];
                $hasNotesOrImagesOrRemovals = false;

                if (is_array($notes)) {
                    foreach ($notes as $j => $noteRow) {
                        $noteText = isset($noteRow['note']) ? trim((string)$noteRow['note']) : '';
                        $files = $this->file("entities.$i.notes.$j.images") ?? [];
                        $hasImages = is_array($files) && count($files) > 0;

                        $removeAttIds = $noteRow['remove_attachment_ids'] ?? [];
                        $wantsRemoveAtt = is_array($removeAttIds) && count($removeAttIds) > 0;

                        if ($noteText !== '' || $hasImages || $wantsRemoveAtt) {
                            $hasNotesOrImagesOrRemovals = true;
                            break;
                        }
                    }
                }

                if ($hasRating || $hasNotesOrImagesOrRemovals || $wantsRemoveNote) {
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
