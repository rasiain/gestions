<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una declaració del 184 tal com es va presentar. Els noms i els imports hi són copiats:
 * no ha de canviar mai perquè canviïn les dades d'origen.
 */
class Model184Declaracio extends Model
{
    protected $table = 'g_184_declaracions';

    protected $fillable = [
        'comunitat_bens_id',
        'any',
        'comunitat_nom',
        'comunitat_nif',
        'retencions',
        'numero_identificatiu',
        'notes',
        'materialitzada_el',
    ];

    protected $casts = [
        'any'               => 'integer',
        'retencions'        => 'decimal:2',
        'materialitzada_el' => 'datetime',
    ];

    public function comunitat(): BelongsTo
    {
        return $this->belongsTo(ComunitatBens::class, 'comunitat_bens_id');
    }

    public function immobles(): HasMany
    {
        return $this->hasMany(Model184Immoble::class, 'declaracio_id');
    }
}
