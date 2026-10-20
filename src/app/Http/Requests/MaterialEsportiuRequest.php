<?php

namespace App\Http\Requests;

use App\Models\MaterialEsportiu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialEsportiuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom'                 => ['required', 'string', 'max:150'],
            'model'               => ['nullable', 'string', 'max:150'],
            // Les bicis es porten al catàleg de bicis: aquí, la resta de material
            'tipus'               => ['required', Rule::in(array_diff(MaterialEsportiu::TIPUS, ['bici']))],
            'estat'               => ['required', Rule::in(MaterialEsportiu::ESTATS)],
            'data_inici'          => ['nullable', 'date'],
            'data_fi'             => ['nullable', 'date', 'after_or_equal:data_inici'],
            'muntat_a_vehicle_id' => ['nullable', 'integer', Rule::exists('g_vehicles', 'id')->where('tipus', 'bici')],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required'               => 'El nom és obligatori.',
            'tipus.in'                   => 'El tipus ha de ser component, sabates o altres.',
            'data_fi.after_or_equal'     => "La data de fi no pot ser anterior a la d'inici.",
            'muntat_a_vehicle_id.exists' => 'Ha de ser una bici del catàleg.',
        ];
    }
}
