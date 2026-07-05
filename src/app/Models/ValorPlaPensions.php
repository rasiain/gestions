<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValorPlaPensions extends Model
{
    protected $table = 'g_pp_valors';

    protected $fillable = [
        'pla_id',
        'data',
        'valor_participacio',
    ];

    protected $casts = [
        'data'              => 'date',
        'valor_participacio' => 'decimal:6',
    ];

    public function pla(): BelongsTo
    {
        return $this->belongsTo(PlaPensions::class, 'pla_id');
    }
}
