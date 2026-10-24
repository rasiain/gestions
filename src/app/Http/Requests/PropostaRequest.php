<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Què es demana a una proposta de sortida: km, desnivell, BTT o carretera i d'on surt. */
class PropostaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'km'        => ['required', 'numeric', 'min:1', 'max:300'],
            'desnivell' => ['required', 'numeric', 'min:0', 'max:10000'],
            'tipus'     => ['required', Rule::in(['btt', 'carretera'])],
            'lat'       => ['required', 'numeric', 'between:-90,90'],
            'lon'       => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'km.required'        => 'Cal dir quants quilòmetres.',
            'km.min'             => 'Com a mínim 1 km.',
            'km.max'             => 'Com a molt 300 km.',
            'desnivell.required' => 'Cal dir quant desnivell.',
            'desnivell.max'      => 'Com a molt 10.000 m.',
            'tipus.in'           => 'Ha de ser BTT o carretera.',
            'lat.required'       => "Cal triar d'on surt al mapa.",
            'lon.required'       => "Cal triar d'on surt al mapa.",
        ];
    }
}
