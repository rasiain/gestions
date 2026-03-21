<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MovimentLloguerIngres extends Model
{
    protected $table = 'g_moviment_lloguer_ingres';

    protected $fillable = [
        'moviment_id',
        'lloguer_id',
        'base_lloguer',
        'gestoria_import',
        'notes',
    ];

    protected $casts = [
        'base_lloguer'    => 'decimal:2',
        'gestoria_import' => 'decimal:2',
    ];

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }

    public function linies(): HasMany
    {
        return $this->hasMany(MovimentLloguerIngresLinia::class, 'ingres_id');
    }
}
