<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El registre de clau C d'un immoble dins d'una declaració presentada.
 */
class Model184Immoble extends Model
{
    protected $table = 'g_184_immobles';

    protected $fillable = [
        'declaracio_id',
        'immoble_id',
        'referencia_cadastral',
        'lloguer_nom',
        'ingressos',
        'despeses',
        'rendiment_net',
        'retencions',
        'amortitzacio',
        'base_repartible',
    ];

    protected $casts = [
        'ingressos'       => 'decimal:2',
        'despeses'        => 'decimal:2',
        'rendiment_net'   => 'decimal:2',
        'retencions'      => 'decimal:2',
        'amortitzacio'    => 'decimal:2',
        'base_repartible' => 'decimal:2',
    ];

    public function declaracio(): BelongsTo
    {
        return $this->belongsTo(Model184Declaracio::class, 'declaracio_id');
    }

    public function caselles(): HasMany
    {
        return $this->hasMany(Model184Casella::class, 'immoble_184_id');
    }

    public function comuners(): HasMany
    {
        return $this->hasMany(Model184Comuner::class, 'immoble_184_id');
    }
}
