<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MovimentCompteCorrentRequest extends FormRequest
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
            'data_moviment' => [
                'required',
                'date',
            ],
            'concepte' => [
                'required',
                'string',
                'max:255',
            ],
            'import' => [
                'required',
                'numeric',
            ],
            'saldo_posterior' => [
                'nullable',
                'numeric',
            ],
            'categoria_id' => [
                'nullable',
                'integer',
                'exists:g_categories,id',
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
            'compte_corrent_id.exists' => 'El compte corrent seleccionat no existeix.',
            'data_moviment.required' => 'La data del moviment és obligatòria.',
            'data_moviment.date' => 'La data del moviment ha de ser una data vàlida.',
            'concepte.required' => 'El concepte és obligatori.',
            'concepte.string' => 'El concepte ha de ser text.',
            'concepte.max' => 'El concepte no pot superar els :max caràcters.',
            'import.required' => 'L\'import és obligatori.',
            'import.numeric' => 'L\'import ha de ser un número.',
            'saldo_posterior.numeric' => 'El saldo ha de ser un número.',
            'categoria_id.exists' => 'La categoria seleccionada no existeix.',
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
            'data_moviment' => 'data del moviment',
            'concepte' => 'concepte',
            'import' => 'import',
            'saldo_posterior' => 'saldo posterior',
            'categoria_id' => 'categoria',
        ];
    }
}
