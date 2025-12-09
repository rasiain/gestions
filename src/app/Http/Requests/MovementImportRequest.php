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
                'mimes:xls,xlsx,csv,txt,qif',
                'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,application/octet-stream',
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
            'edited_movements' => [
                'nullable',
                'array',
            ],
            'edited_movements.*.data_moviment' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'edited_movements.*.concepte' => [
                'nullable',
                'string',
                'max:255',
            ],
            'edited_movements.*.categoria_id' => [
                'nullable',
                'integer',
                'exists:g_categories,id',
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
            'file.mimes' => 'Format de fitxer no vàlid. Formats acceptats: XLS, XLSX, CSV, TXT, QIF.',
            'file.max' => 'El fitxer no pot superar 100MB.',
            'compte_corrent_id.required' => 'El compte corrent és obligatori.',
            'compte_corrent_id.exists' => 'El compte corrent seleccionat no existeix.',
            'bank_type.required' => 'El tipus de banc és obligatori.',
            'bank_type.in' => 'Tipus de banc no vàlid.',
            'import_mode.in' => 'Mode d\'importació no vàlid.',
            'edited_movements.*.data_moviment.date_format' => 'Format de data incorrecte.',
            'edited_movements.*.concepte.max' => 'El concepte no pot superar 255 caràcters.',
            'edited_movements.*.categoria_id.exists' => 'La categoria seleccionada no existeix.',
        ];
    }
}
