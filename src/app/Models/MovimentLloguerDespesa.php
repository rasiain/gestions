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
        'categoria',
        'proveidor_id',
        'notes',
        'base_imposable',
        'iva_import',
    ];

    protected $casts = [
        'base_imposable' => 'decimal:2',
        'iva_import'     => 'decimal:2',
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
}
