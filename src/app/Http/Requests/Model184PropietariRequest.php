<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quota i amortització d'un comuner sobre un immoble, per a un exercici.
 */
class Model184PropietariRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'immoble_id'         => ['required', 'integer', 'exists:g_immobles,id'],
            'persona_id'         => ['required', 'integer', 'exists:g_persones,id'],
            'any'                => ['required', 'integer', 'min:1900', 'max:2999'],
            'quota'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amortitzacio_anual' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'quota.max' => 'La quota no pot passar del 100 %.',
            'amortitzacio_anual.min' => "L'amortització no pot ser negativa.",
        ];
    }
}
