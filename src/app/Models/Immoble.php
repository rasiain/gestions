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
        'poblacio',
        'superficie_construida',
        'superficie_parcela',
        'us',
        'valor_sol',
        'valor_construccio',
        'valor_adquisicio',
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
            ->withPivot('data_inici', 'data_fi', 'quota', 'amortitzacio_anual')
            ->withTimestamps();
    }

    /**
     * Els trams d'administració, del més antic al més recent. Un tram sense data d'inici
     * és el de des de sempre i va primer.
     */
    public function administracions()
    {
        return $this->hasMany(AdministracioImmoble::class)
            ->orderByRaw('data_inici IS NOT NULL')
            ->orderBy('data_inici');
    }

    /**
     * L'administració vigent a una data (avui, per defecte), si n'hi havia cap.
     */
    public function administracioA(\Carbon\CarbonInterface|string|null $data = null): ?AdministracioImmoble
    {
        $data ??= now();

        return $this->administracions->last(fn (AdministracioImmoble $a) => $a->vigentA($data));
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
