<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * El que s'hi ha escrit a mà sobre un salt del patrimoni.
 *
 * Amb `moviment_id`, la nota només hi posa text i els altres camps són del moviment. Sense,
 * és una nota sencera i ha de dir la seva data, i qui afecta.
 */
class PatrimoniNota extends Model
{
    protected $table = 'g_patrimoni_notes';

    protected $fillable = ['moviment_id', 'data', 'titol', 'descripcio', 'import', 'ocult'];

    protected $casts = [
        'data'   => 'date',
        'import' => 'decimal:2',
        'ocult'  => 'boolean',
    ];

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }

    public function titulars(): BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'g_patrimoni_nota_titular', 'nota_id', 'titular_id')
            ->withTimestamps();
    }
}
