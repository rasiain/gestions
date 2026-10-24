<?php

namespace App\Http\Requests;

use App\Services\AltitudTerrenyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Com es mira l'altitud: per a una sortida (un mètode buit torna a la de per defecte) o per defecte. */
class AltitudPreferenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $perDefecte = $this->routeIs('bicis.altitud-defecte');

        return [
            'metode' => [$perDefecte ? 'required' : 'nullable', Rule::in(AltitudTerrenyService::METODES)],
            'font'   => ['nullable', Rule::in(['millor', ...AltitudTerrenyService::FONTS])],
        ];
    }

    public function messages(): array
    {
        return [
            'metode.required' => 'Cal triar el mètode.',
            'metode.in'       => 'El mètode ha de ser dispositiu, terreny o combinat.',
            'font.in'         => 'La font ha de ser la millor disponible, ICGC o IGN.',
        ];
    }
}
