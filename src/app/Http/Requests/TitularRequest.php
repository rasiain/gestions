<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TitularRequest extends FormRequest
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
            'nom' => [
                'required',
                'string',
                'max:20',
            ],
            'cognoms' => [
                'required',
                'string',
                'max:50',
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
            'nom.required' => 'El nom és obligatori.',
            'nom.string' => 'El nom ha de ser text.',
            'nom.max' => 'El nom no pot superar els :max caràcters.',
            'cognoms.required' => 'Els cognoms són obligatoris.',
            'cognoms.string' => 'Els cognoms han de ser text.',
            'cognoms.max' => 'Els cognoms no poden superar els :max caràcters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nom' => 'nom',
            'cognoms' => 'cognoms',
        ];
    }
}
