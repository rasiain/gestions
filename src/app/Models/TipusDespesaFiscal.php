<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipusDespesaFiscal extends Model
{
    protected $table = 'g_tipus_despesa_fiscal';

    protected $fillable = [
        'codi',
        'descripcio',
    ];
}
