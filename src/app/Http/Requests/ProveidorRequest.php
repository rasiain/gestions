<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProveidorRequest extends FormRequest
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
            'nom_rao_social' => [
                'required',
                'string',
                'max:255',
            ],
            'nif_cif' => [
                'nullable',
                'string',
                'max:20',
            ],
            'adreca' => [
                'nullable',
                'string',
                'max:255',
            ],
            'correu_electronic' => [
                'nullable',
                'email',
                'max:255',
            ],
            'telefons' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nom_rao_social.required' => 'El nom o raó social és obligatori.',
            'nom_rao_social.max' => 'El nom o raó social no pot superar els :max caràcters.',
            'nif_cif.max' => 'El NIF/CIF no pot superar els :max caràcters.',
            'adreca.max' => "L'adreça no pot superar els :max caràcters.",
            'correu_electronic.email' => 'El correu electrònic ha de ser una adreça vàlida.',
            'correu_electronic.max' => 'El correu electrònic no pot superar els :max caràcters.',
            'telefons.max' => 'Els telèfons no poden superar els :max caràcters.',
        ];
    }
}
