<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El que una persona (o unes quantes) té d'un títol en un compte.
 *
 * Els titulars surten del compte del contracte, com als fons. El compte de rendibilitat
 * és un altre: és on arriben els cupons.
 */
class RendaFixaContracte extends Model
{
    protected $table = 'g_rf_contractes';

    protected $fillable = [
        'titol_id',
        'compte_corrent_id',
        'compte_rendibilitat_id',
        'nominal',
        'data_compra',
        'data_venciment',
        'notes',
    ];

    protected $casts = [
        'nominal'        => 'decimal:2',
        'data_compra'    => 'date',
        'data_venciment' => 'date',
    ];

    public function titol(): BelongsTo
    {
        return $this->belongsTo(RendaFixaTitol::class, 'titol_id');
    }

    public function compteCorrent(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_corrent_id');
    }

    public function compteRendibilitat(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_rendibilitat_id');
    }

    public function valors(): HasMany
    {
        return $this->hasMany(RendaFixaValor::class, 'contracte_id');
    }

    public function rendibilitats(): HasMany
    {
        return $this->hasMany(RendaFixaRendibilitat::class, 'contracte_id');
    }

    /** El valor patrimonial més recent fins a la data indicada; si no n'hi ha, el nominal. */
    public function valorAData(?string $data = null): float
    {
        $valor = $this->valors
            ->when($data !== null, fn ($v) => $v->filter(fn (RendaFixaValor $x) => $x->data->toDateString() <= $data))
            ->sortByDesc(fn (RendaFixaValor $x) => $x->data->timestamp)
            ->first();

        return $valor !== null ? (float) $valor->valor_patrimonial : (float) $this->nominal;
    }
}
