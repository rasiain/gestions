<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CapitalSocialContracteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'compte_corrent_id'   => ['required', 'integer', 'exists:g_comptes_corrents,id'],
            // On arriben els interessos: sol ser un compte corrent i no el del capital
            'compte_rendiment_id' => ['nullable', 'integer', 'exists:g_comptes_corrents,id'],
            'data_alta'           => ['nullable', 'date'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
