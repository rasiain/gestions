<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContracteFons extends Model
{
    protected $table = 'g_fi_contractes';

    protected $fillable = [
        'fons_id',
        'compte_corrent_id',
        'data_inici',
        'data_fi',
        'notes',
    ];

    protected $casts = [
        'data_inici' => 'date',
        'data_fi'    => 'date',
    ];

    public function fons(): BelongsTo
    {
        return $this->belongsTo(FonsInversio::class, 'fons_id');
    }

    public function compteCorrent(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_corrent_id');
    }

    public function aportacions(): HasMany
    {
        return $this->hasMany(AportacioFons::class, 'contracte_id');
    }

    public function despeses(): HasMany
    {
        return $this->hasMany(DespesaFons::class, 'contracte_id');
    }
}
