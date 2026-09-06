<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quant valia el contracte una data. En euros, com ve a l'extracte. */
class RendaFixaValor extends Model
{
    protected $table = 'g_rf_valors';

    protected $fillable = ['contracte_id', 'data', 'valor_patrimonial'];

    protected $casts = [
        'data'              => 'date',
        'valor_patrimonial' => 'decimal:2',
    ];
}
