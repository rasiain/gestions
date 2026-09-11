<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El capital social que una persona (o unes quantes) té en una cooperativa.
 *
 * Si l'aportació viu en un compte —una cooperativa de crèdit— els titulars surten d'ell,
 * com als fons i a la renda fixa. Si no en té —les aportacions a una cooperativa de
 * consum, que no són cap compte i que només tenen un certificat anual— el contracte porta
 * l'emissor i els seus propis titulars. El compte de rendiment és un altre: és on arriben
 * els interessos, si arriben enlloc.
 */
class CapitalSocialContracte extends Model
{
    protected $table = 'g_cs_contractes';

    protected $fillable = [
        'emissor',
        'nif',
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

    /** Els titulars propis, per a les aportacions que no tenen cap compte d'on treure'ls. */
    public function titularsPropis(): BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'g_cs_contracte_titular', 'contracte_id', 'titular_id')
            ->withTimestamps();
    }

    /**
     * Qui és el titular de l'aportació: els del compte, o els seus propis si no en té.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Persona>
     */
    public function titulars()
    {
        return $this->compteCorrent?->titulars ?? $this->titularsPropis;
    }

    /** El nom de qui l'emet: la cooperativa, o l'entitat del compte. */
    public function getEmissorVisibleAttribute(): ?string
    {
        return $this->emissor ?? $this->compteCorrent?->entitat;
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
        return $this->valorVigentA($data)?->total ?? 0.0;
    }

    public function valorVigentA(?string $data = null): ?CapitalSocialValor
    {
        return $this->valors
            ->when($data !== null, fn ($v) => $v->filter(fn (CapitalSocialValor $x) => $x->data->toDateString() <= $data))
            ->sortByDesc(fn (CapitalSocialValor $x) => $x->data->timestamp)
            ->first();
    }
}
