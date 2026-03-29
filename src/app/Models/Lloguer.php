<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lloguer extends Model
{
    protected $table = 'g_lloguers';

    protected $fillable = [
        'nom',
        'acronim',
        'immoble_id',
        'compte_corrent_id',
        'base_euros',
        'proveidor_gestoria_id',
        'gestoria_percentatge',
        'es_habitatge',
        'retencio_irpf',
    ];

    protected $casts = [
        'base_euros'           => 'decimal:2',
        'gestoria_percentatge' => 'decimal:2',
        'es_habitatge'         => 'boolean',
        'retencio_irpf'        => 'boolean',
    ];

    public function immoble(): BelongsTo
    {
        return $this->belongsTo(Immoble::class);
    }

    public function compteCorrent(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class);
    }

    public function contractes(): HasMany
    {
        return $this->hasMany(Contracte::class);
    }

    public function gestoria(): BelongsTo
    {
        return $this->belongsTo(Proveidor::class, 'proveidor_gestoria_id');
    }
}
