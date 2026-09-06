<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RendaFixaContracteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titol_id'               => ['required', 'integer', 'exists:g_rf_titols,id'],
            'compte_corrent_id'      => ['required', 'integer', 'exists:g_comptes_corrents,id'],
            // On arriben els cupons: sol ser un compte corrent i no el del títol
            'compte_rendibilitat_id' => ['nullable', 'integer', 'exists:g_comptes_corrents,id'],
            'nominal'                => ['required', 'numeric', 'min:0'],
            'data_compra'            => ['nullable', 'date'],
            'data_venciment'         => ['nullable', 'date', 'after_or_equal:data_compra'],
            'notes'                  => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_venciment.after_or_equal' => 'El venciment no pot ser anterior a la compra.',
        ];
    }
}
