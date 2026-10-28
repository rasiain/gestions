<?php

namespace App\Http\Requests;

use App\Services\ImportacioRecorregutsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Quines activitats de Baixades s'importen: només fitxers amb nom de Garmin Connect que hi siguin. */
class ImportacioBaixadesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $carpeta = app(ImportacioRecorregutsService::class)->carpetaBaixades();

        return [
            'fitxers'    => ['required', 'array', 'max:200'],
            'fitxers.*'  => ['string', function ($atribut, $nom, $falla) use ($carpeta) {
                if ($carpeta === null) {
                    $falla('No hi ha cap carpeta de Baixades configurada (GARMIN_CARPETA_BAIXADES).');
                } elseif (basename($nom) !== $nom || ! ImportacioRecorregutsService::esDeGarmin($nom)) {
                    $falla("«{$nom}» no és cap fitxer d'activitat de Garmin Connect.");
                } elseif (! is_file("{$carpeta}/{$nom}")) {
                    $falla("«{$nom}» ja no és a Baixades.");
                }
            }],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('g_vehicles', 'id')->where('tipus', 'bici')],
        ];
    }

    public function messages(): array
    {
        return ['fitxers.required' => 'Tria alguna activitat de Baixades.'];
    }
}
