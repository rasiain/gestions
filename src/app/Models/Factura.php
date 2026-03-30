<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factura extends Model
{
    protected $table = 'g_factures';

    protected $fillable = [
        'lloguer_id',
        'contracte_id',
        'any',
        'mes',
        'base',
        'iva_percentatge',
        'iva_import',
        'irpf_percentatge',
        'irpf_import',
        'total',
        'estat',
        'moviment_id',
        'numero_factura',
        'data_emissio',
        'notes',
    ];

    protected $casts = [
        'base'             => 'decimal:2',
        'iva_percentatge'  => 'decimal:2',
        'iva_import'       => 'decimal:2',
        'irpf_percentatge' => 'decimal:2',
        'irpf_import'      => 'decimal:2',
        'total'            => 'decimal:2',
        'data_emissio'     => 'date',
    ];

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }

    public function contracte(): BelongsTo
    {
        return $this->belongsTo(Contracte::class);
    }

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }

    public function linies(): HasMany
    {
        return $this->hasMany(FacturaLinia::class);
    }
}
