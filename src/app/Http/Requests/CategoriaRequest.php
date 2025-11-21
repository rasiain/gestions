<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoriaRequest extends FormRequest
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
            'compte_corrent_id' => [
                'required',
                'integer',
                'exists:g_comptes_corrents,id',
            ],
            'nom' => [
                'required',
                'string',
                'max:100',
            ],
            'categoria_pare_id' => [
                'nullable',
                'integer',
                'exists:g_categories,id',
            ],
            'ordre' => [
                'nullable',
                'integer',
                'min:0',
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
            'compte_corrent_id.required' => 'El compte corrent és obligatori.',
            'compte_corrent_id.integer' => 'El compte corrent ha de ser un número vàlid.',
            'compte_corrent_id.exists' => 'El compte corrent seleccionat no existeix.',
            'nom.required' => 'El nom de la categoria és obligatori.',
            'nom.string' => 'El nom ha de ser text.',
            'nom.max' => 'El nom no pot superar els :max caràcters.',
            'categoria_pare_id.integer' => 'La categoria pare ha de ser un número vàlid.',
            'categoria_pare_id.exists' => 'La categoria pare seleccionada no existeix.',
            'ordre.integer' => 'L\'ordre ha de ser un número enter.',
            'ordre.min' => 'L\'ordre no pot ser negatiu.',
            'ordre.max' => 'L\'ordre no pot superar :max.',
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
            'compte_corrent_id' => 'compte corrent',
            'nom' => 'nom',
            'categoria_pare_id' => 'categoria pare',
            'ordre' => 'ordre',
        ];
    }
}
