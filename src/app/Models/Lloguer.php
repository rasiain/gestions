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
        'es_habitatge',
        'retencio_irpf',
        'iva_percentatge',
        'irpf_percentatge',
        'ruta_descarrega',
        'ruta_export',
    ];

    protected $casts = [
        'base_euros'           => 'decimal:2',
        'es_habitatge'         => 'boolean',
        'retencio_irpf'        => 'boolean',
        'iva_percentatge'      => 'decimal:2',
        'irpf_percentatge'     => 'decimal:2',
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

    /**
     * La gestoria del lloguer és l'administradora de l'immoble a aquella data: qui cobra
     * la renda i en descompta la comissió.
     */
    public function administracioA(\Carbon\CarbonInterface|string|null $data = null): ?AdministracioImmoble
    {
        return $this->immoble?->administracioA($data);
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    public function revisionsIpc(): HasMany
    {
        return $this->hasMany(LloguerRevisioIpc::class);
    }

}
