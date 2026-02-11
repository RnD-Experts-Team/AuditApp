<?php

namespace App\Http\Requests\CustomReport;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCustomReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reportId = $this->route('custom_report');

        return [
            'name' => 'required|string|max:255|unique:custom_reports,name,'.$reportId,
            'entity_ids' => 'required|array|min:1',
            'entity_ids.*' => 'exists:entities,id',
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
