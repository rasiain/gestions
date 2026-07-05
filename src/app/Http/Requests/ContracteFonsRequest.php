<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContracteFonsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fons_id'          => ['required', 'integer', 'exists:g_fi_fons,id'],
            'compte_corrent_id' => ['required_without:compte_nou', 'nullable', 'integer', 'exists:g_comptes_corrents,id'],
            'compte_nou'              => ['nullable', 'boolean'],
            'compte_referencia'       => ['required_if:compte_nou,true', 'nullable', 'string', 'max:24'],
            'compte_nom'              => ['nullable', 'string', 'max:100'],
            'compte_entitat_id'       => ['nullable', 'integer', 'exists:g_entitats,id'],
            'compte_entitat_nova_nom' => ['nullable', 'string', 'max:200'],
            'titular_ids'     => ['nullable', 'array'],
            'titular_ids.*'   => ['integer', 'exists:g_persones,id'],
            'data_inici'      => ['required', 'date'],
            'data_fi'          => ['nullable', 'date', 'after_or_equal:data_inici'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'fons_id.required'              => 'El fons és obligatori.',
            'compte_corrent_id.required_without' => 'Cal seleccionar un compte o crear-ne un de nou.',
            'compte_referencia.required_if' => 'La referència del compte és obligatòria.',
            'compte_entitat_id.exists'       => "L'entitat seleccionada no existeix.",
            'data_inici.required'           => 'La data d\'inici és obligatòria.',
            'data_fi.after_or_equal'        => 'La data de fi ha de ser igual o posterior a la d\'inici.',
        ];
    }
}
