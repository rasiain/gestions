<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LlogaterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:50'],
            'cognoms' => ['required', 'string', 'max:100'],
            'identificador' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'El nom és obligatori.',
            'nom.max' => 'El nom no pot superar els :max caràcters.',
            'cognoms.required' => 'Els cognoms són obligatoris.',
            'cognoms.max' => 'Els cognoms no poden superar els :max caràcters.',
            'identificador.max' => "L'identificador no pot superar els :max caràcters.",
        ];
    }
}
