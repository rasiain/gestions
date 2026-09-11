<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un contracte de capital social, amb compte o sense.
 *
 * Les aportacions a una cooperativa de crèdit viuen en un compte, i d'ell en surten els
 * titulars. Les d'una cooperativa de consum no són cap compte: porten el nom de qui les
 * emet i els titulars s'hi trien a mà. N'hi ha d'haver una de les dues coses.
 */
class CapitalSocialContracteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'compte_corrent_id'   => ['nullable', 'integer', 'exists:g_comptes_corrents,id', 'required_without:emissor'],
            'emissor'             => ['nullable', 'string', 'max:150', 'required_without:compte_corrent_id'],
            'nif'                 => ['nullable', 'string', 'max:20'],
            // Sense compte no hi ha d'on treure els titulars: s'han de dir
            'titulars'            => ['array', Rule::requiredIf(fn () => ! $this->filled('compte_corrent_id'))],
            'titulars.*'          => ['integer', 'exists:g_persones,id'],
            'compte_rendiment_id' => ['nullable', 'integer', 'exists:g_comptes_corrents,id'],
            'data_alta'           => ['nullable', 'date'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'compte_corrent_id.required_without' => "Tria el compte de l'aportació o escriu qui l'emet.",
            'emissor.required_without'           => "Tria el compte de l'aportació o escriu qui l'emet.",
            'titulars.required'                  => 'Una aportació sense compte necessita que en diguis els titulars.',
        ];
    }

    /**
     * El que va a `g_cs_contractes`: els titulars van a la seva taula.
     *
     * @return array<string, mixed>
     */
    public function dadesDelContracte(): array
    {
        return collect($this->validated())->except('titulars')->all();
    }

    /**
     * Els titulars propis, que només tenen sentit sense compte: amb compte surten d'ell, i
     * desar-los aquí a més seria una segona resposta a la mateixa pregunta.
     *
     * @return array<int, int>
     */
    public function titularsPropis(): array
    {
        return $this->filled('compte_corrent_id') ? [] : ($this->validated()['titulars'] ?? []);
    }
}
