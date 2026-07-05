<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValorFons extends Model
{
    protected $table = 'g_fi_valors';

    protected $fillable = [
        'fons_id',
        'data',
        'valor_participacio',
    ];

    protected $casts = [
        'data'              => 'date',
        'valor_participacio' => 'decimal:6',
    ];

    public function fons(): BelongsTo
    {
        return $this->belongsTo(FonsInversio::class, 'fons_id');
    }
}
