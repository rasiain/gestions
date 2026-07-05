<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespesaFons extends Model
{
    protected $table = 'g_fi_despeses';

    protected $fillable = [
        'contracte_id',
        'data',
        'import',
        'concepte',
    ];

    protected $casts = [
        'data'   => 'date',
        'import' => 'decimal:2',
    ];

    public function contracte(): BelongsTo
    {
        return $this->belongsTo(ContracteFons::class, 'contracte_id');
    }
}
