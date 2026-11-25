<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompteCorrent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_comptes_corrents';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'compte_corrent',
        'nom',
        'entitat_id',
        'ordre',
        'tipus',
        'last_import_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ordre' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'bank_type',
        'entitat',
    ];

    public function entitatRelacio(): BelongsTo
    {
        return $this->belongsTo(Entitat::class, 'entitat_id');
    }

    public function getEntitatAttribute(): ?string
    {
        return $this->getRelationValue('entitatRelacio')?->nom;
    }

    /**
     * Get the titulars (persones) associated with this compte corrent.
     */
    public function titulars()
    {
        return $this->belongsToMany(Persona::class, 'g_compte_corrent_titular', 'compte_corrent_id', 'titular_id')
            ->withTimestamps();
    }

    /**
     * Get the categories associated with this compte corrent.
     */
    public function categories()
    {
        return $this->hasMany(Categoria::class, 'compte_corrent_id')->orderBy('ordre');
    }

    /**
     * Get the moviments associated with this compte corrent.
     */
    public function moviments()
    {
        return $this->hasMany(MovimentCompteCorrent::class, 'compte_corrent_id');
    }

    /**
     * El saldo actual de cada compte d'una llista, d'una sola consulta: si no, `saldo_actual`
     * en fa una per compte (i una altra cada cop que es llegeix).
     */
    public function scopeAmbSaldoActual(Builder $query): Builder
    {
        return $query->addSelect(['saldo_actual' => MovimentCompteCorrent::select('saldo_posterior')
            ->whereColumn('compte_corrent_id', $this->getTable() . '.id')
            ->orderByDesc('data_moviment')
            ->orderByDesc('id')
            ->limit(1)]);
    }

    /**
     * El saldo actual: el `saldo_posterior` del moviment més recent. Amb `ambSaldoActual()` ja ve
     * de la consulta; si no, es busca.
     */
    public function getSaldoActualAttribute(): ?float
    {
        if (array_key_exists('saldo_actual', $this->attributes)) {
            return $this->attributes['saldo_actual'] === null ? null : (float) $this->attributes['saldo_actual'];
        }

        $lastMoviment = $this->moviments()
            ->orderByDesc('data_moviment')
            ->orderByDesc('id')
            ->first();

        return $lastMoviment?->saldo_posterior;
    }

    /**
     * Get the bank type based on the entity name.
     *
     * @return string|null
     */
    public function getBankTypeAttribute(): ?string
    {
        if ($this->last_import_type) {
            return $this->last_import_type;
        }

        $entitat = strtolower($this->getRelationValue('entitatRelacio')?->nom ?? '');

        if (str_contains($entitat, 'enginyer')) {
            return 'caixa_enginyers';
        }

        if (str_contains($entitat, 'caixabank')) {
            return 'caixabank';
        }

        // Dos comptes de BBVA, i els seus fitxers no diuen de quin són: reconèixer-los
        // tots dos fa que la importació pregunti a quin va, en comptes de triar l'únic
        // que coneixia (el darrer que s'hi havia importat).
        if (str_contains($entitat, 'bbva')) {
            return 'bbva';
        }

        if (str_contains($entitat, 'kmymoney') || str_contains($entitat, 'kmoney')) {
            return 'kmymoney';
        }

        return null;
    }
}
