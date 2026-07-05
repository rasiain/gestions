<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValorFonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fons_id'           => ['required', 'integer', 'exists:g_fi_fons,id'],
            'data'              => ['required', 'date'],
            'valor_participacio' => ['required', 'numeric', 'min:0.000001'],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required'              => 'La data és obligatòria.',
            'valor_participacio.required' => 'El valor de la participació és obligatori.',
            'valor_participacio.min'     => 'El valor ha de ser positiu.',
        ];
    }
}
