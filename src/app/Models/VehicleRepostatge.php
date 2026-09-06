<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un repostatge d'un vehicle a motor.
 *
 * Els litres, els quilòmetres fets i el consum no es desen: es calculen a partir del que
 * s'apunta, que és com ho fa el full de càlcul d'on venen aquestes dades.
 */
class VehicleRepostatge extends Model
{
    protected $table = 'g_vehicles_motor_repostatges';

    protected $fillable = [
        'vehicle_id',
        'data',
        'km_totals',
        'preu_litre',
        'cost',
        'benzinera',
        'diposit_ple',
        'moviment_id',
        'notes',
    ];

    protected $casts = [
        'data'        => 'date',
        'km_totals'   => 'integer',
        'preu_litre'  => 'decimal:3',
        'cost'        => 'decimal:2',
        'diposit_ple' => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }

    /** Litres posats: el cost dividit pel preu del litre. */
    public function litres(): ?float
    {
        $preu = (float) $this->preu_litre;

        return $preu > 0 ? round((float) $this->cost / $preu, 2) : null;
    }
}
