<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Les dades físiques de l'usuari un dia: pes, % de greix, pulsació en repòs i pulsació màxima, totes opcionals.
 * Cada una val des d'aquest dia fins al registre següent que la porti (`DadesFisiquesService`).
 */
class SalutRegistre extends Model
{
    protected $table = 'g_salut_registres';

    protected $guarded = ['id'];

    public const CAMPS = ['pes', 'greix', 'fc_repos', 'fc_maxima'];

    protected function casts(): array
    {
        return [
            'data'      => 'date',
            'pes'       => 'float',
            'greix'     => 'float',
            'fc_repos'  => 'integer',
            'fc_maxima' => 'integer',
        ];
    }
}
