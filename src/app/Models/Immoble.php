<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Immoble extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_immobles';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'referencia_cadastral',
        'adreca',
        'superficie_construida',
        'superficie_parcela',
        'us',
        'valor_sol',
        'valor_construccio',
        'valor_adquisicio',
        'referencia_administracio',
        'administrador_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'superficie_construida' => 'decimal:2',
        'superficie_parcela' => 'decimal:2',
        'valor_sol' => 'decimal:2',
        'valor_construccio' => 'decimal:2',
        'valor_adquisicio' => 'decimal:2',
    ];

    /**
     * Get the propietaris (persones) associated with this immoble.
     */
    public function propietaris()
    {
        return $this->belongsToMany(Persona::class, 'g_propietaris_immobles', 'immoble_id', 'persona_id')
            ->withPivot('data_inici', 'data_fi')
            ->withTimestamps();
    }

    /**
     * Get the administrador (proveidor) associated with this immoble.
     */
    public function administrador()
    {
        return $this->belongsTo(Proveidor::class, 'administrador_id');
    }

    /**
     * Get the valor cadastral (calculated field).
     *
     * @return float|null
     */
    public function getValorCadastralAttribute(): ?float
    {
        if ($this->valor_sol === null && $this->valor_construccio === null) {
            return null;
        }

        return ($this->valor_sol ?? 0) + ($this->valor_construccio ?? 0);
    }
}
