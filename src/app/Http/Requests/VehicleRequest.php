<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom'            => ['required', 'string', 'max:100'],
            'tipus'          => ['required', Rule::in(Vehicle::TIPUS)],
            'combustible'    => ['nullable', Rule::in(Vehicle::COMBUSTIBLES)],
            'marca'          => ['nullable', 'string', 'max:60'],
            'model'          => ['nullable', 'string', 'max:60'],
            'matricula'      => ['nullable', 'string', 'max:20'],
            'any_fabricacio' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'data_alta'      => ['nullable', 'date'],
            'data_baixa'     => ['nullable', 'date', 'after_or_equal:data_alta'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'ordre'          => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required'             => 'El nom és obligatori.',
            'tipus.in'                 => 'El tipus ha de ser cotxe, moto, bici o altres.',
            'data_baixa.after_or_equal' => "La data de baixa no pot ser anterior a la d'alta.",
        ];
    }
}
