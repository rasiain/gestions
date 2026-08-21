<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssegurancaPatroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'etiqueta' => ['required', 'string', 'max:100'],
            'patro'    => ['required', 'string', 'max:100'],
            'actiu'    => ['boolean'],
            'ordre'    => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'etiqueta.required' => "L'etiqueta és obligatòria.",
            'etiqueta.max'      => "L'etiqueta no pot superar els :max caràcters.",
            'patro.required'    => 'El patró és obligatori.',
            'patro.max'         => 'El patró no pot superar els :max caràcters.',
        ];
    }
}
