<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Total anual d'una taxa per a un grup de la vista d'impostos municipals.
 */
class TaxaRebut extends Model
{
    protected $table = 'g_taxes_rebuts';

    protected $fillable = [
        'grup',
        'immoble_id',
        'tipus',
        'any',
        'referencia',
        'import_total',
        'terminis_previstos',
        'repercutible',
        'concepte_repercussio',
        'notes',
    ];

    protected $casts = [
        'any'                => 'integer',
        'import_total'       => 'decimal:2',
        'terminis_previstos' => 'integer',
        'repercutible'       => 'boolean',
    ];

    public function immoble(): BelongsTo
    {
        return $this->belongsTo(Immoble::class);
    }

    /** Clau amb què es casa amb una fila de la vista de taxes. */
    public function clau(): string
    {
        return self::clauDe($this->grup, $this->tipus);
    }

    public static function clauDe(string $grup, string $tipus): string
    {
        return $grup . '||' . $tipus;
    }
}
