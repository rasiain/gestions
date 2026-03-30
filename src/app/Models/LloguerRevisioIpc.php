<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LloguerRevisioIpc extends Model
{
    protected $table = 'g_lloguer_revisions_ipc';

    protected $fillable = [
        'lloguer_id',
        'any_aplicacio',
        'base_anterior',
        'base_nova',
        'ipc_percentatge',
        'data_efectiva',
        'mesos_regularitzats',
    ];

    protected $casts = [
        'base_anterior'     => 'decimal:2',
        'base_nova'         => 'decimal:2',
        'ipc_percentatge'   => 'decimal:2',
        'data_efectiva'     => 'date',
    ];

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }
}
