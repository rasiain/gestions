<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaLloguerFiscal extends Model
{
    protected $table = 'g_categoria_lloguer_fiscal';
    protected $primaryKey = 'categoria';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'categoria',
        'tipus_despesa_fiscal_id',
    ];

    public function tipusDespesaFiscal()
    {
        return $this->belongsTo(TipusDespesaFiscal::class, 'tipus_despesa_fiscal_id');
    }
}
