<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComunitatBensRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('comunitats_ben')?->id;

        return [
            'nom' => ['required', 'string', 'max:255'],
            'nif' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('g_comunitats_bens', 'nif')->ignore($id),
            ],
            'adreca'          => ['nullable', 'string', 'max:255'],
            'activitat'       => ['nullable', 'string', 'max:50'],
            'codi_activitat'  => ['nullable', 'string', 'max:3'],
            'epigraf_iae'     => ['nullable', 'integer', 'min:0', 'max:9999'],
            'comuner_ids'     => ['nullable', 'array'],
            'comuner_ids.*'   => ['integer', 'exists:g_persones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'El nom és obligatori.',
            'nom.max'      => 'El nom no pot superar els :max caràcters.',
            'nif.max'      => 'El NIF no pot superar els :max caràcters.',
            'nif.unique'   => 'Aquest NIF ja existeix.',
        ];
    }
}
