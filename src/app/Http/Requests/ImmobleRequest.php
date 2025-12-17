<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImmobleRequest extends FormRequest
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
        $immobleId = $this->route('immoble');

        return [
            'referencia_cadastral' => [
                'required',
                'string',
                'max:255',
                Rule::unique('g_immobles', 'referencia_cadastral')->ignore($immobleId),
            ],
            'adreca' => [
                'required',
                'string',
                'max:255',
            ],
            'superficie_construida' => [
                'nullable',
                'numeric',
                'min:0',
                'max:99999999.99',
            ],
            'superficie_parcela' => [
                'nullable',
                'numeric',
                'min:0',
                'max:99999999.99',
            ],
            'us' => [
                'nullable',
                'in:residencial,oficines,magatzem_estacionament,agrari',
            ],
            'valor_sol' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
            'valor_construccio' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
            'valor_adquisicio' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
            'referencia_administracio' => [
                'nullable',
                'string',
                'max:50',
            ],
            'administrador_id' => [
                'nullable',
                'integer',
                'exists:g_proveidors,id',
            ],
            'propietari_ids' => [
                'nullable',
                'array',
            ],
            'propietari_ids.*' => [
                'integer',
                'exists:g_persones,id',
            ],
            'propietari_data_inici' => [
                'nullable',
                'array',
            ],
            'propietari_data_inici.*' => [
                'date',
            ],
            'propietari_data_fi' => [
                'nullable',
                'array',
            ],
            'propietari_data_fi.*' => [
                'nullable',
                'date',
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
            'referencia_cadastral.required' => 'La referència cadastral és obligatòria.',
            'referencia_cadastral.unique' => 'Aquesta referència cadastral ja existeix.',
            'adreca.required' => "L'adreça és obligatòria.",
            'superficie_construida.numeric' => 'La superfície construïda ha de ser un número.',
            'superficie_parcela.numeric' => 'La superfície de la parcel·la ha de ser un número.',
            'us.in' => "L'ús seleccionat no és vàlid.",
            'valor_sol.numeric' => 'El valor del sòl ha de ser un número.',
            'valor_construccio.numeric' => 'El valor de la construcció ha de ser un número.',
            'valor_adquisicio.numeric' => "El valor d'adquisició ha de ser un número.",
        ];
    }
}
