<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajust manual d'una categoria d'assegurança: el nom del grup, el municipi o
 * l'asseguradora quan l'arbre no els diu, la inclusió d'una pòlissa que cap
 * patró no enganxa i l'exclusió del que no n'és cap.
 */
class AssegurancaPolissa extends Model
{
    protected $table = 'g_assegurances_polisses';

    protected $fillable = [
        'categoria_id',
        'objecte',
        'poblacio',
        'companyia',
        'tipus',
        'inclou',
        'ocult',
    ];

    protected $casts = [
        'categoria_id' => 'integer',
        'inclou'       => 'boolean',
        'ocult'        => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }
}
