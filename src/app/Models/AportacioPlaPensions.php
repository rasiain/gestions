<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AportacioPlaPensions extends Model
{
    protected $table = 'g_pp_aportacions';

    protected $fillable = [
        'contracte_id',
        'data',
        'import',
        'participacions',
        'notes',
    ];

    protected $casts = [
        'data'           => 'date',
        'import'         => 'decimal:2',
        'participacions' => 'decimal:6',
    ];

    public function contracte(): BelongsTo
    {
        return $this->belongsTo(ContractePlaPensions::class, 'contracte_id');
    }
}
