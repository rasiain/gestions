<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajust manual d'una categoria de taxa: immoble i municipi quan l'arbre no els
 * diu, o quan cal agrupar dues categories en el mateix immoble.
 */
class TaxaImmoble extends Model
{
    protected $table = 'g_taxes_immobles';

    protected $fillable = [
        'categoria_id',
        'immoble',
        'poblacio',
        'ocult',
    ];

    protected $casts = [
        'categoria_id' => 'integer',
        'ocult'        => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }
}
