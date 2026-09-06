<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una despesa d'un vehicle a motor que no és combustible. */
class VehicleDespesa extends Model
{
    protected $table = 'g_vehicles_motor_despeses';

    /** Els tipus que surten del full: la resta va a «altres». */
    public const TIPUS = [
        'reparacio'         => 'Reparació/Revisió',
        'itv'               => 'ITV',
        'asseguranca'       => 'Assegurança',
        'impost_circulacio' => 'Impost de circulació',
        'altres'            => 'Altres',
    ];

    protected $fillable = [
        'vehicle_id',
        'data',
        'tipus',
        'import',
        'km_totals',
        'taller',
        'motiu',
        'moviment_id',
    ];

    protected $casts = [
        'data'      => 'date',
        'import'    => 'decimal:2',
        'km_totals' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }
}
