<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * L'ajust s'identifica pel CAMÍ de la categoria, no per l'id: el mateix camí
 * existeix a cada compte que l'hagi importat, i totes aquelles categories volen
 * dir el mateix.
 */
class AssegurancaPolissaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cami'      => ['required', 'string', 'max:1000'],
            'objecte'   => ['nullable', 'string', 'max:150'],
            'poblacio'  => ['nullable', 'string', 'max:100'],
            'companyia' => ['nullable', 'string', 'max:100'],
            'tipus'     => ['nullable', 'string', 'max:60'],
            'inclou'    => ['boolean'],
            'ocult'     => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'cami.required' => 'Falta el camí de la categoria.',
        ];
    }
}
