<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LloguerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Una ruta enganxada des del Finder o del terminal pot portar cometes o
     * escapaments de shell («Platja\ d\'Aro»). Amb cometes deixa de començar per
     * «/» i PHP la resol com a relativa: el fitxer acaba dins de public/ sense
     * cap error. Amb escapaments, la carpeta no existeix i se'n crea una altra.
     */
    protected function prepareForValidation(): void
    {
        foreach (['ruta_descarrega', 'ruta_export'] as $camp) {
            if (is_string($this->input($camp))) {
                $ruta = trim($this->input($camp), " \t\n\r\0\x0B'\"");
                $ruta = preg_replace('/\\\\(.)/u', '$1', $ruta);
                $this->merge([$camp => $ruta === '' ? null : $ruta]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'nom'              => ['required', 'string', 'max:100'],
            'acronim'          => ['nullable', 'string', 'max:20'],
            'immoble_id'       => ['required', 'integer', 'exists:g_immobles,id'],
            'compte_corrent_id' => ['required', 'integer', 'exists:g_comptes_corrents,id'],
            'base_euros'            => ['nullable', 'numeric', 'min:0'],
            'es_habitatge'          => ['boolean'],
            'retencio_irpf'         => ['boolean'],
            'iva_percentatge'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'irpf_percentatge'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ruta_descarrega'       => ['nullable', 'string', 'max:500'],
            'ruta_export'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required'              => 'El nom és obligatori.',
            'nom.max'                   => 'El nom no pot superar els :max caràcters.',
            'acronim.max'               => "L'acrònim no pot superar els :max caràcters.",
            'immoble_id.required'       => "L'immoble és obligatori.",
            'immoble_id.exists'         => "L'immoble seleccionat no és vàlid.",
            'compte_corrent_id.required' => 'El compte corrent és obligatori.',
            'compte_corrent_id.exists'  => 'El compte corrent seleccionat no és vàlid.',
            'base_euros.numeric'        => 'La base ha de ser un número.',
            'base_euros.min'            => 'La base no pot ser negativa.',
        ];
    }
}
