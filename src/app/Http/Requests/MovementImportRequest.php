<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MovementImportRequest extends FormRequest
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
        return [
            'file' => [
                'required',
                'file',
                'mimes:xls,xlsx,csv,txt,qif,html',
                'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,text/html,application/octet-stream',
                'max:102400', // 100MB
            ],
            'compte_corrent_id' => [
                'required',
                'integer',
                'exists:g_comptes_corrents,id',
            ],
            'bank_type' => [
                'required',
                'string',
                'in:caixa_enginyers,caixabank,kmymoney',
            ],
            'import_mode' => [
                'nullable',
                'string',
                'in:from_beginning,from_last_db',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'El fitxer és obligatori.',
            'file.mimes' => 'Format de fitxer no vàlid. Formats acceptats: XLS, XLSX, CSV, TXT, QIF, HTML.',
            'file.max' => 'El fitxer no pot superar 100MB.',
            'compte_corrent_id.required' => 'El compte corrent és obligatori.',
            'compte_corrent_id.exists' => 'El compte corrent seleccionat no existeix.',
            'bank_type.required' => 'El tipus de banc és obligatori.',
            'bank_type.in' => 'Tipus de banc no vàlid.',
            'import_mode.in' => 'Mode d\'importació no vàlid.',
        ];
    }
}
