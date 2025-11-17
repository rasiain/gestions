<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessExcelTransactionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // You can add authorization logic here if needed
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $format = $this->input('format', 'auto');

        // Use file extensions instead of MIME types for better compatibility
        // Many banks export "xls" files that are actually HTML
        if ($format === 'html') {
            $fileValidation = 'mimes:html,htm,xls';
        } else {
            $fileValidation = 'mimes:xlsx,xls,csv,html,htm';
        }

        return [
            'excel_file' => [
                'required',
                'file',
                $fileValidation,
                'max:10240', // 10MB max
            ],
            'header_lines' => [
                'nullable',
                'integer',
                'min:0',
                'max:50', // Reasonable limit to prevent abuse
            ],
            'format' => [
                'nullable',
                'in:auto,html',
            ],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'excel_file.required' => 'Please select an Excel file to upload.',
            'excel_file.file' => 'The uploaded file is not valid.',
            'excel_file.mimes' => 'The file must be a valid Excel file (.xlsx, .xls) or CSV file (.csv).',
            'excel_file.max' => 'The file size must not exceed 10MB.',
            'header_lines.integer' => 'The header lines must be a whole number.',
            'header_lines.min' => 'The header lines cannot be negative.',
            'header_lines.max' => 'The header lines cannot exceed 50.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'excel_file' => 'Excel file',
            'header_lines' => 'header lines to skip',
        ];
    }
}
