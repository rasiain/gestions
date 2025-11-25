<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimentCompteCorrent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_moviments_comptes_corrents';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'data_moviment',
        'concepte',
        'import',
        'saldo_posterior',
        'hash',
        'conciliat',
        'notes',
        'compte_corrent_id',
        'categoria_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data_moviment' => 'date',
        'import' => 'decimal:2',
        'saldo_posterior' => 'decimal:2',
        'conciliat' => 'boolean',
        'compte_corrent_id' => 'integer',
        'categoria_id' => 'integer',
    ];

    /**
     * Get the compte corrent associated with this movement.
     */
    public function compteCorrent()
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_corrent_id');
    }

    /**
     * Get the category associated with this movement.
     */
    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    /**
     * Scope to filter by compte corrent.
     */
    public function scopePerCompteCorrent($query, $compteCorrentId)
    {
        return $query->where('compte_corrent_id', $compteCorrentId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('data_moviment', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by category.
     */
    public function scopePerCategoria($query, $categoriaId)
    {
        return $query->where('categoria_id', $categoriaId);
    }

    /**
     * Scope to get only reconciled movements.
     */
    public function scopeConciliats($query)
    {
        return $query->where('conciliat', true);
    }

    /**
     * Scope to get only non-reconciled movements.
     */
    public function scopeNoConciliats($query)
    {
        return $query->where('conciliat', false);
    }

    /**
     * Scope to get movements without category.
     */
    public function scopeSenseCategoria($query)
    {
        return $query->whereNull('categoria_id');
    }

    /**
     * Generate hash for duplicate detection.
     * Hash is based on: date + concept + amount + account
     *
     * @param string $dataMoviment
     * @param string $concepte
     * @param float $import
     * @param int $compteCorrentId
     * @return string
     */
    public static function generateHash(string $dataMoviment, string $concepte, float $import, int $compteCorrentId): string
    {
        $data = sprintf(
            '%s|%s|%.2f|%d',
            $dataMoviment,
            trim($concepte),
            $import,
            $compteCorrentId
        );

        return hash('sha256', $data);
    }
}
