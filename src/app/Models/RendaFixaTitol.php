<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un valor de renda fixa del catàleg, identificat per l'ISIN. */
class RendaFixaTitol extends Model
{
    protected $table = 'g_rf_titols';

    protected $fillable = ['isin', 'nom', 'emissor', 'notes'];

    public function contractes(): HasMany
    {
        return $this->hasMany(RendaFixaContracte::class, 'titol_id');
    }
}
