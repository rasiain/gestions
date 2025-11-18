<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessDataFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
        // CSV files with tab separators often have txt extension or text/plain MIME type
        if ($format === 'html') {
            $fileValidation = 'mimes:html,htm,xls';
        } else {
            $fileValidation = 'mimes:xlsx,xls,csv,txt,html,htm';
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
                'max:50',
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
            'excel_file.required' => 'Selecciona un fitxer de dades per pujar.',
            'excel_file.file' => 'El fitxer pujat no és vàlid.',
            'excel_file.mimes' => 'El fitxer ha de ser un fitxer Excel (.xlsx, .xls), CSV (.csv, .txt) o HTML vàlid.',
            'excel_file.max' => 'La mida del fitxer no pot superar els 10MB.',
            'header_lines.integer' => 'Les línies de capçalera han de ser un número enter.',
            'header_lines.min' => 'Les línies de capçalera no poden ser negatives.',
            'header_lines.max' => 'Les línies de capçalera no poden superar 50.',
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
            'excel_file' => 'fitxer de dades',
            'header_lines' => 'línies de capçalera a saltar',
        ];
    }
}
