<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Patró que detecta una assegurança pel nom d'un node de l'arbre de categories.
 */
class AssegurancaPatro extends Model
{
    protected $table = 'g_assegurances_patrons';

    protected $fillable = [
        'etiqueta',
        'patro',
        'actiu',
        'ordre',
    ];

    protected $casts = [
        'actiu' => 'boolean',
        'ordre' => 'integer',
    ];
}
