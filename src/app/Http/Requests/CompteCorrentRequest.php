<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompteCorrentRequest extends FormRequest
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
            'compte_corrent' => [
                'required',
                'string',
                'max:24',
            ],
            'nom' => [
                'nullable',
                'string',
                'max:100',
            ],
            'entitat_id' => [
                'required_without:entitat_nova_nom',
                'nullable',
                'integer',
                'exists:g_entitats,id',
            ],
            'entitat_nova_nom' => [
                'required_without:entitat_id',
                'nullable',
                'string',
                'max:200',
            ],
            'ordre' => [
                'nullable',
                'integer',
                'min:0',
                'max:255',
            ],
            'tipus' => [
                'nullable',
                'string',
                'in:corrent,fons_inversio',
            ],
            'titular_ids' => [
                'nullable',
                'array',
            ],
            'titular_ids.*' => [
                'integer',
                'exists:g_persones,id',
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
            'compte_corrent.required' => 'El número de compte corrent és obligatori.',
            'compte_corrent.string' => 'El número de compte corrent ha de ser text.',
            'compte_corrent.max' => 'El número de compte corrent no pot superar els :max caràcters.',
            'nom.string' => 'El nom ha de ser text.',
            'nom.max' => 'El nom no pot superar els :max caràcters.',
            'entitat_id.required_without'       => "Cal seleccionar una entitat o crear-ne una de nova.",
            'entitat_nova_nom.required_without'  => "Cal seleccionar una entitat o crear-ne una de nova.",
            'entitat_nova_nom.max'               => "El nom de l'entitat no pot superar els :max caràcters.",
            'ordre.integer' => 'L\'ordre ha de ser un número enter.',
            'ordre.min' => 'L\'ordre no pot ser negatiu.',
            'ordre.max' => 'L\'ordre no pot superar :max.',
            'titular_ids.array' => 'Els titulars han de ser una llista.',
            'titular_ids.*.integer' => 'Cada titular ha de ser un número vàlid.',
            'titular_ids.*.exists' => 'Un o més titulars seleccionats no existeixen.',
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
            'compte_corrent' => 'compte corrent',
            'nom' => 'nom',
            'entitat_id'       => 'entitat bancària',
            'entitat_nova_nom' => 'nova entitat',
            'ordre' => 'ordre',
            'titular_ids' => 'titulars',
        ];
    }
}
