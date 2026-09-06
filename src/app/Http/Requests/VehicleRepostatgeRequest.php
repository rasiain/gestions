<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VehicleRepostatgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'  => ['required', 'integer', 'exists:g_vehicles,id'],
            'data'        => ['required', 'date'],
            // El comptador, que només puja
            'km_totals'   => ['required', 'integer', 'min:0'],
            'preu_litre'  => ['required', 'numeric', 'min:0'],
            'cost'        => ['required', 'numeric', 'min:0'],
            'benzinera'   => ['nullable', 'string', 'max:100'],
            'diposit_ple' => ['boolean'],
            'moviment_id' => ['nullable', 'integer', 'exists:g_moviments_comptes_corrents,id'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'km_totals.required'  => 'Falten els quilòmetres del comptador.',
            'preu_litre.required' => 'Falta el preu del litre.',
            'cost.required'       => 'Falta el cost.',
        ];
    }
}
