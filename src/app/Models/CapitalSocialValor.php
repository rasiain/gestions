<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quants títols i a quin nominal, una data.
 *
 * El total no s'hi desa: és el producte dels dos i, desat, només podria contradir-los.
 */
class CapitalSocialValor extends Model
{
    protected $table = 'g_cs_valors';

    protected $fillable = ['contracte_id', 'data', 'titols', 'valor_unitari'];

    protected $casts = [
        'data'          => 'date',
        'titols'        => 'integer',
        'valor_unitari' => 'decimal:2',
    ];

    public function getTotalAttribute(): float
    {
        return round($this->titols * (float) $this->valor_unitari, 2);
    }
}
