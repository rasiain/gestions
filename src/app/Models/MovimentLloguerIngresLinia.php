<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentLloguerIngresLinia extends Model
{
    protected $table = 'g_moviment_lloguer_ingres_linia';

    protected $fillable = [
        'ingres_id',
        'tipus',
        'descripcio',
        'import',
        'proveidor_id',
    ];

    protected $casts = [
        'import' => 'decimal:2',
    ];

    public function ingres(): BelongsTo
    {
        return $this->belongsTo(MovimentLloguerIngres::class, 'ingres_id');
    }

    public function proveidor(): BelongsTo
    {
        return $this->belongsTo(Proveidor::class);
    }
}
