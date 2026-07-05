<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractePlaPensions extends Model
{
    protected $table = 'g_pp_contractes';

    protected $fillable = [
        'pla_id',
        'compte_corrent_id',
        'data_inici',
        'data_fi',
        'notes',
    ];

    protected $casts = [
        'data_inici' => 'date',
        'data_fi'    => 'date',
    ];

    public function pla(): BelongsTo
    {
        return $this->belongsTo(PlaPensions::class, 'pla_id');
    }

    public function compteCorrent(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class, 'compte_corrent_id');
    }

    public function aportacions(): HasMany
    {
        return $this->hasMany(AportacioPlaPensions::class, 'contracte_id');
    }

    public function despeses(): HasMany
    {
        return $this->hasMany(DespesaPlaPensions::class, 'contracte_id');
    }
}
