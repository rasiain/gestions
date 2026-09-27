<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LloguerEscombraries extends Model
{
    protected $table = 'g_lloguers_escombraries';

    protected $fillable = [
        'lloguer_id',
        'any',
        'import_total',
        'factura_separada',
        'notes',
    ];

    protected $casts = [
        'any'              => 'integer',
        'import_total'     => 'decimal:2',
        'factura_separada' => 'boolean',
    ];

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }

    public function fraccions(): HasMany
    {
        return $this->hasMany(LloguerEscombrariesFraccio::class, 'escombraries_id')->orderBy('ordre');
    }
}
