<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportacioSortidesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fitxers'    => ['required', 'array', 'max:200'],
            // El FIT no té cap tipus MIME conegut: es mira l'extensió
            'fitxers.*'  => ['file', 'max:102400', function ($atribut, $fitxer, $falla) {
                if (! in_array(strtolower($fitxer->getClientOriginalExtension()), ['fit', 'zip'], true)) {
                    $falla("«{$fitxer->getClientOriginalName()}» no és un .fit ni un .zip.");
                }
            }],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('g_vehicles', 'id')->where('tipus', 'bici')],
        ];
    }

    public function messages(): array
    {
        return [
            'fitxers.required' => 'Cal triar algun fitxer.',
            'fitxers.max'      => 'Com a molt 200 fitxers alhora.',
            'fitxers.*.max'    => 'Algun fitxer passa de 100 MB.',
        ];
    }
}
