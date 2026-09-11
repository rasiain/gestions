<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Una nota del patrimoni, damunt d'un moviment o sencera.
 *
 * Amb moviment només s'hi escriu text: la data, l'import i els titulars són d'ell i tornar
 * a demanar-los aquí seria una segona resposta a la mateixa pregunta. Sense moviment, la
 * nota s'ha de sostenir sola i necessita almenys data i títol.
 */
class PatrimoniNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sobreMoviment = $this->filled('moviment_id');

        return [
            'moviment_id' => ['nullable', 'integer', 'exists:g_moviments_comptes_corrents,id'],
            'data'        => [Rule::requiredIf(! $sobreMoviment), 'nullable', 'date'],
            'titol'       => [Rule::requiredIf(! $sobreMoviment), 'nullable', 'string', 'max:200'],
            'descripcio'  => ['nullable', 'string', 'max:2000'],
            'import'      => ['nullable', 'numeric'],
            'ocult'       => ['boolean'],
            'titulars'    => ['array'],
            'titulars.*'  => ['integer', 'exists:g_persones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required'  => "Una nota que no és de cap moviment necessita la data del que va passar.",
            'titol.required' => 'Posa-hi un títol: és el que es llegeix al gràfic.',
        ];
    }
}
