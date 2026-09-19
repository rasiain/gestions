<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'poblacio' => [
                'nullable',
                'string',
                'max:100',
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
            // Trams d'administració: l'empresa, com l'identifica i la comissió, amb dates
            'administracions' => [
                'nullable',
                'array',
            ],
            'administracions.*.proveidor_id' => [
                'required',
                'integer',
                'exists:g_proveidors,id',
            ],
            'administracions.*.referencia' => [
                'nullable',
                'string',
                'max:50',
            ],
            'administracions.*.percentatge' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'administracions.*.data_inici' => [
                'nullable',
                'date',
            ],
            'administracions.*.data_fi' => [
                'nullable',
                'date',
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

            // La proporció de titularitat d'aquell tram; buida vol dir «a parts iguals»
            'propietari_quota' => [
                'nullable',
                'array',
            ],
            'propietari_quota.*' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
        ];
    }

    /**
     * Un immoble té una sola administradora cada dia: dos trams que s'encavalquen
     * farien que un cobrament no sabés de quina empresa és la comissió.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $trams = collect($this->input('administracions', []))->values();

                foreach ($trams as $i => $tram) {
                    $inici = $tram['data_inici'] ?? null;
                    $fi    = $tram['data_fi'] ?? null;

                    if ($inici && $fi && $fi < $inici) {
                        $validator->errors()->add('administracions', 'Un tram d\'administració acaba abans de començar.');

                        return;
                    }

                    foreach ($trams->slice($i + 1) as $altre) {
                        $altreInici = $altre['data_inici'] ?? null;
                        $altreFi    = $altre['data_fi'] ?? null;

                        // Sense data d'inici és des de sempre; sense data de fi, fins avui
                        $comencaAbansQueAcabiAltre = !$inici || !$altreFi || $inici <= $altreFi;
                        $altreComencaAbansQueAcabi = !$altreInici || !$fi || $altreInici <= $fi;

                        if ($comencaAbansQueAcabiAltre && $altreComencaAbansQueAcabi) {
                            $validator->errors()->add('administracions', 'Hi ha dos trams d\'administració que s\'encavalquen: tanca l\'anterior amb una data de fi.');

                            return;
                        }
                    }
                }
            },
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
            'administracions.*.proveidor_id.required' => "Cada tram d'administració ha de tenir l'empresa.",
            'administracions.*.percentatge.numeric'   => 'La comissió ha de ser un número.',
        ];
    }
}
