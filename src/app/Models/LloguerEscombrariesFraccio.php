<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LloguerEscombrariesFraccio extends Model
{
    protected $table = 'g_lloguers_escombraries_fraccions';

    protected $fillable = [
        'escombraries_id',
        'ordre',
        'mes',
        'import',
    ];

    protected $casts = [
        'ordre'  => 'integer',
        'mes'    => 'integer',
        'import' => 'decimal:2',
    ];

    public function escombraries(): BelongsTo
    {
        return $this->belongsTo(LloguerEscombraries::class, 'escombraries_id');
    }

    /** «Escombraries industrials (1 de 3)», que és el que surt a la factura. */
    public function descripcio(int $total): string
    {
        return sprintf('Escombraries industrials (%d de %d)', $this->ordre, $total);
    }
}
