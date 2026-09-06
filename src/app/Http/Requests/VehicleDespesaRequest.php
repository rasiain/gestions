<?php

namespace App\Http\Requests;

use App\Models\VehicleDespesa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleDespesaRequest extends FormRequest
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
            'tipus'       => ['required', Rule::in(array_keys(VehicleDespesa::TIPUS))],
            'import'      => ['required', 'numeric'],
            // L'assegurança i l'impost no passen pel taller: no en porten
            'km_totals'   => ['nullable', 'integer', 'min:0'],
            'taller'      => ['nullable', 'string', 'max:100'],
            'motiu'       => ['nullable', 'string', 'max:2000'],
            'moviment_id' => ['nullable', 'integer', 'exists:g_moviments_comptes_corrents,id'],
        ];
    }
}
