<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Què valia l'aportació una data.
 *
 * Ve de dues maneres, segons qui l'emeti: una cooperativa de crèdit ho desglossa en
 * **títols × nominal unitari** i una de consum en dona el **saldo** i prou. El total no es
 * desa quan es pot multiplicar —seria una tercera dada que pot contradir les altres dues—
 * i quan no hi ha títols, l'import és l'única dada que hi ha.
 */
class CapitalSocialValor extends Model
{
    protected $table = 'g_cs_valors';

    protected $fillable = ['contracte_id', 'data', 'titols', 'valor_unitari', 'import'];

    protected $casts = [
        'data'          => 'date',
        'titols'        => 'integer',
        'valor_unitari' => 'decimal:2',
        'import'        => 'decimal:2',
    ];

    public function getTotalAttribute(): float
    {
        if ($this->titols !== null && $this->valor_unitari !== null) {
            return round($this->titols * (float) $this->valor_unitari, 2);
        }

        return round((float) $this->import, 2);
    }
}
