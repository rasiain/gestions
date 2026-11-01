<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\SalutRegistre;

/** Un registre de dades físiques: una data i, com a mínim, una de les tres dades. */
class SalutRegistreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $registre = $this->route('registre');

        return [
            // La data es desa amb l'hora (el cast), i per això no val un `unique` en sec
            'data'      => ['required', 'date', function (string $atribut, mixed $valor, \Closure $falla) use ($registre) {
                $existeix = SalutRegistre::whereDate('data', $valor)
                    ->when($registre, fn ($q) => $q->whereKeyNot($registre->id))
                    ->exists();
                if ($existeix) {
                    $falla('Ja hi ha un registre en aquesta data: edita aquell.');
                }
            }],
            'pes'       => ['nullable', 'numeric', 'between:30,250', 'required_without_all:greix,fc_repos,fc_maxima'],
            'greix'     => ['nullable', 'numeric', 'between:3,60'],
            'fc_repos'  => ['nullable', 'integer', 'between:25,120'],
            'fc_maxima' => ['nullable', 'integer', 'between:100,230'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'pes.required_without_all'  => 'Escriu com a mínim una dada: el pes, el greix o alguna de les pulsacions.',
            'greix.between'             => 'El greix és un percentatge: entre 3 i 60.',
            'pes.between'               => 'El pes ha de ser entre 30 i 250 kg.',
            'fc_repos.between'          => 'La pulsació en repòs ha de ser entre 25 i 120.',
            'fc_maxima.between'         => 'La pulsació màxima ha de ser entre 100 i 230.',
        ];
    }
}
