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
        $tipus = $this->input('tipus', 'persona');

        $rules = [
            'tipus' => ['required', 'in:persona,empresa'],
        ];

        if ($tipus === 'persona') {
            $rules['persona_id'] = ['required', 'exists:g_persones,id'];
        } else {
            $rules['nom_rao_social'] = ['required', 'string', 'max:150'];
            $rules['nif']            = ['nullable', 'string', 'max:20'];
            $rules['adreca']         = ['nullable', 'string', 'max:200'];
            $rules['codi_postal']    = ['nullable', 'string', 'max:10'];
            $rules['poblacio']       = ['nullable', 'string', 'max:100'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'tipus.required'          => 'El tipus és obligatori.',
            'tipus.in'                => 'El tipus ha de ser persona o empresa.',
            'persona_id.required'     => 'Cal seleccionar una persona.',
            'persona_id.exists'       => 'La persona seleccionada no existeix.',
            'nom_rao_social.required' => 'La raó social és obligatòria.',
            'nom_rao_social.max'      => 'La raó social no pot superar els :max caràcters.',
        ];
    }
}
