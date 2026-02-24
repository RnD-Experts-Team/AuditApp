<?php

namespace App\Http\Requests\CustomReport;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreCustomReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:custom_reports,name',
            'entity_ids' => 'required|array|min:1',
            'entity_ids.*' => 'exists:entities,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The report name is required.',
            'name.string' => 'The report name must be a valid string.',
            'name.max' => 'The report name must not exceed 255 characters.',
            'name.unique' => 'The report name must be unique. A report with this name already exists.',

            'entity_ids.required' => 'You must select at least one entity.',
            'entity_ids.array' => 'The entities field must be an array.',
            'entity_ids.min' => 'You must select at least one entity.',

            'entity_ids.*.exists' => 'Each selected entity must be a valid entity.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
