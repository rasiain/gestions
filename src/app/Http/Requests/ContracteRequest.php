<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContracteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lloguer_id'  => ['required', 'integer', 'exists:g_lloguers,id'],
            'data_inici'  => ['required', 'date'],
            'data_fi'     => ['nullable', 'date', 'after_or_equal:data_inici'],
            'llogater_ids' => ['nullable', 'array'],
            'llogater_ids.*' => ['integer', 'exists:g_llogaters,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'lloguer_id.required'  => 'El lloguer és obligatori.',
            'lloguer_id.exists'    => 'El lloguer seleccionat no és vàlid.',
            'data_inici.required'  => 'La data d\'inici és obligatòria.',
            'data_inici.date'      => 'La data d\'inici no és vàlida.',
            'data_fi.date'         => 'La data de fi no és vàlida.',
            'data_fi.after_or_equal' => 'La data de fi ha de ser igual o posterior a la d\'inici.',
        ];
    }
}
