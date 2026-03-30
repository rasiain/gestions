<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaLinia extends Model
{
    protected $table = 'g_factura_linies';

    protected $fillable = [
        'factura_id',
        'concepte',
        'descripcio',
        'base',
        'iva_import',
        'irpf_import',
    ];

    protected $casts = [
        'base'        => 'decimal:2',
        'iva_import'  => 'decimal:2',
        'irpf_import' => 'decimal:2',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
