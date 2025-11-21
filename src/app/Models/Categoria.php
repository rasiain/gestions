<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_categories';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'compte_corrent_id',
        'nom',
        'categoria_pare_id',
        'ordre',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'compte_corrent_id' => 'integer',
        'ordre' => 'integer',
        'categoria_pare_id' => 'integer',
    ];

    /**
     * Get the compte corrent associated with this category.
     */
    public function compteCorrent()
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_corrent_id');
    }

    /**
     * Get the parent category.
     */
    public function pare()
    {
        return $this->belongsTo(Categoria::class, 'categoria_pare_id');
    }

    /**
     * Get the child categories.
     */
    public function fills()
    {
        return $this->hasMany(Categoria::class, 'categoria_pare_id')->orderBy('ordre');
    }

    /**
     * Scope to get only root categories (no parent).
     */
    public function scopeArrel($query)
    {
        return $query->whereNull('categoria_pare_id')->orderBy('ordre');
    }

    /**
     * Scope to filter by compte corrent.
     */
    public function scopePerCompteCorrent($query, $compteCorrentId)
    {
        return $query->where('compte_corrent_id', $compteCorrentId);
    }
}
