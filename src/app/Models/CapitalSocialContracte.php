<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El capital social que una persona (o unes quantes) té en una cooperativa de crèdit.
 *
 * Els titulars surten del compte del contracte, com als fons i a la renda fixa. El compte
 * de rendiment és un altre: és on arriben els interessos.
 */
class CapitalSocialContracte extends Model
{
    protected $table = 'g_cs_contractes';

    protected $fillable = [
        'compte_corrent_id',
        'compte_rendiment_id',
        'data_alta',
        'notes',
    ];

    protected $casts = [
        'data_alta' => 'date',
    ];

    public function compteCorrent(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_corrent_id');
    }

    public function compteRendiment(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_rendiment_id');
    }

    public function valors(): HasMany
    {
        return $this->hasMany(CapitalSocialValor::class, 'contracte_id');
    }

    public function rendiments(): HasMany
    {
        return $this->hasMany(CapitalSocialRendiment::class, 'contracte_id');
    }

    /**
     * El valor més recent fins a la data indicada: títols per nominal unitari.
     *
     * Sense cap valor declarat no val res, a diferència de la renda fixa: allà hi ha el
     * nominal del contracte, i aquí el que es va aportar només consta als valors.
     */
    public function valorAData(?string $data = null): float
    {
        $valor = $this->valorVigentA($data);

        return $valor === null ? 0.0 : round($valor->titols * (float) $valor->valor_unitari, 2);
    }

    public function valorVigentA(?string $data = null): ?CapitalSocialValor
    {
        return $this->valors
            ->when($data !== null, fn ($v) => $v->filter(fn (CapitalSocialValor $x) => $x->data->toDateString() <= $data))
            ->sortByDesc(fn (CapitalSocialValor $x) => $x->data->timestamp)
            ->first();
    }
}
