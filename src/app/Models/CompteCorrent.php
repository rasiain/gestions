<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'entitat',
        'ordre',
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
    ];

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
     * Get the current balance (saldo_posterior from the most recent movement).
     */
    public function getSaldoActualAttribute(): ?float
    {
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
        $entitat = strtolower($this->entitat);

        if (str_contains($entitat, 'enginyer')) {
            return 'caixa_enginyers';
        }

        if (str_contains($entitat, 'caixabank')) {
            return 'caixabank';
        }

        if (str_contains($entitat, 'kmymoney') || str_contains($entitat, 'kmoney')) {
            return 'kmymoney';
        }

        return null;
    }
}
