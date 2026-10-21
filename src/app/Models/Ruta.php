<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una ruta canònica d'un nivell de tolerància: un grup de sortides que fan el mateix recorregut,
 * amb una d'elles com a representant. Dada derivada de `rutes:calcula`.
 */
class Ruta extends Model
{
    protected $table = 'g_rutes';

    protected $guarded = ['id'];

    public function canonica(): BelongsTo
    {
        return $this->belongsTo(SortidaBici::class, 'canonica_id');
    }

    public function sortidesDelGrup(): BelongsToMany
    {
        return $this->belongsToMany(SortidaBici::class, 'g_sortida_ruta', 'ruta_id', 'sortida_id')->withPivot('semblanca');
    }
}
