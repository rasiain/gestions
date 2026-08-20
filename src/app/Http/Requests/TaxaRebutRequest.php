<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaxaRebutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grup'                 => ['required', 'string', 'max:180'],
            'immoble_id'           => ['nullable', 'integer', 'exists:g_immobles,id'],
            'tipus'                => ['required', 'string', 'max:60'],
            'any'                  => ['required', 'integer', 'min:1990', 'max:2100'],
            'referencia'           => ['nullable', 'string', 'max:80'],
            'import_total'         => ['required', 'numeric', 'min:0.01'],
            'terminis_previstos'   => ['nullable', 'integer', 'min:1', 'max:12'],
            'repercutible'         => ['boolean'],
            'concepte_repercussio' => ['nullable', 'string', 'max:40'],
            'notes'                => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'import_total.required' => "L'import total del rebut és obligatori.",
            'import_total.min'      => "L'import total ha de ser més gran que zero.",
            'terminis_previstos.max' => 'Un rebut no pot tenir més de :max terminis.',
        ];
    }
}
