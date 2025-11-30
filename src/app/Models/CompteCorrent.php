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
     * Get the titulars associated with this compte corrent.
     */
    public function titulars()
    {
        return $this->belongsToMany(Titular::class, 'g_compte_corrent_titular', 'compte_corrent_id', 'titular_id')
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
