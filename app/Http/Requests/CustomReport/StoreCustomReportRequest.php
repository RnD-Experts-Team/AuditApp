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
            'entity_ids.required' => 'You must select at least one entity.',
            'entity_ids.min' => 'You must select at least one entity.',
        ];
    }
    /*
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
    */
}
