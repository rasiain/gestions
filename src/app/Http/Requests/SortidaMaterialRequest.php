<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SortidaMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'     => ['required', 'integer', Rule::exists('g_vehicles', 'id')->where('tipus', 'bici')],
            'material_ids'   => ['present', 'array'],
            // La bici no es tria aquí sinó amb `vehicle_id`: la llista és la resta de material
            'material_ids.*' => ['integer', 'distinct', Rule::exists('g_material_esportiu', 'id')->whereNot('tipus', 'bici')],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Cal triar la bici.',
            'vehicle_id.exists'   => 'Ha de ser una bici del catàleg.',
            'material_ids.*.exists' => 'Algun dels components triats no existeix o és una bici.',
        ];
    }
}
