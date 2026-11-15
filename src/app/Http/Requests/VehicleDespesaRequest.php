<?php

namespace App\Http\Requests;

use App\Models\MovimentCompteCorrent;
use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Cada vehicle, els seus: una bici no passa l'ITV
            'tipus'       => ['required', Rule::in(array_keys(VehicleDespesa::tipusDe(Vehicle::find($this->input('vehicle_id'))?->tipus)))],
            'import'      => ['required', 'numeric'],
            // L'assegurança i l'impost no passen pel taller: no en porten
            'km_totals'   => ['nullable', 'integer', 'min:0'],
            'taller'      => ['nullable', 'string', 'max:100'],
            'motiu'       => ['nullable', 'string', 'max:2000'],
            'moviment_id' => ['nullable', 'integer', 'exists:g_moviments_comptes_corrents,id'],
            'material_id' => ['nullable', 'integer', 'exists:g_material_esportiu,id'],
        ];
    }

    /**
     * Un moviment es pot repartir entre diverses despeses, però entre totes no en poden prendre més
     * del que va sortir del banc.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $movimentId = $this->input('moviment_id');
            if (! $movimentId || $validator->errors()->isNotEmpty()) {
                return;
            }

            $moviment = MovimentCompteCorrent::find($movimentId);
            $despesa = $this->route('despesa');
            $queda = round(abs((float) $moviment->import) - VehicleDespesa::assignatDe($moviment->id, $despesa?->id), 2);

            if ((float) $this->input('import') > $queda + 0.005) {
                $validator->errors()->add('import', 'Del moviment només en queden ' . number_format($queda, 2, ',', '.') . ' € per assignar.');
            }
        }];
    }
}
