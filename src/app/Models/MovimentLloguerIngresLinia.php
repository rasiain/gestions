<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentLloguerIngresLinia extends Model
{
    /** Es resta de la base per arribar al net que va entrar al banc. */
    public const DEDUCCIO = 'deduccio';

    /** Ja és dins de la base: només la desglossa (escombraries retornades pel llogater). */
    public const REPERCUSSIO = 'repercussio';

    protected $table = 'g_moviment_lloguer_ingres_linia';

    protected $fillable = [
        'ingres_id',
        'tipus',
        'naturalesa',
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
