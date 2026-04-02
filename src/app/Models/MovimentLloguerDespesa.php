<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentLloguerDespesa extends Model
{
    protected $table = 'g_moviment_lloguer_despesa';

    protected $fillable = [
        'moviment_id',
        'lloguer_id',
        'numero_factura',
        'concepte',
        'categoria',
        'proveidor_id',
        'notes',
        'base_imposable',
        'iva_percentatge',
        'iva_import',
        'tipus_despesa_fiscal_id',
    ];

    protected $casts = [
        'base_imposable'  => 'decimal:2',
        'iva_percentatge' => 'decimal:2',
        'iva_import'      => 'decimal:2',
    ];

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }

    public function proveidor(): BelongsTo
    {
        return $this->belongsTo(Proveidor::class);
    }

    public function tipusDespesaFiscal(): BelongsTo
    {
        return $this->belongsTo(TipusDespesaFiscal::class, 'tipus_despesa_fiscal_id');
    }
}
