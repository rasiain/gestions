<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DespesaPlaPensions extends Model
{
    protected $table = 'g_pp_despeses';

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
        return $this->belongsTo(ContractePlaPensions::class, 'contracte_id');
    }
}
