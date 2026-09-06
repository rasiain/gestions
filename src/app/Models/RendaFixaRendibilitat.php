<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un cupó o interès cobrat: el benefici de l'any surt de sumar-los. */
class RendaFixaRendibilitat extends Model
{
    protected $table = 'g_rf_rendibilitats';

    protected $fillable = ['contracte_id', 'data', 'import', 'moviment_id', 'notes'];

    protected $casts = [
        'data'   => 'date',
        'import' => 'decimal:2',
    ];

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }
}
