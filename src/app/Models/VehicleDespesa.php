<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una despesa d'un vehicle que no és combustible: d'un vehicle a motor o d'una bici (la taula es diu
 * `_motor_` perquè va néixer abans de les bicis). Pot venir d'un moviment del banc, sencer o en part:
 * el `moviment_id` no és únic i cada despesa porta el seu import.
 */
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

    /**
     * Els de les bicis: ni ITV ni impost de circulació, i en canvi peces i roba. Components són les
     * peces que es munten; manteniment, el que es gasta sense ser de cap peça (lubricant, netejador,
     * líquid tubeless).
     */
    public const TIPUS_BICI = [
        'reparacio'   => 'Reparació/Revisió',
        'components'  => 'Components',
        'manteniment' => 'Manteniment i consumibles',
        'roba'        => 'Roba i accessoris',
        'asseguranca' => 'Assegurança',
        'altres'      => 'Altres',
    ];

    /** Els tipus que admet un vehicle segons el seu tipus (cotxe, moto, bici). */
    public static function tipusDe(?string $tipusVehicle): array
    {
        return $tipusVehicle === 'bici' ? self::TIPUS_BICI : self::TIPUS;
    }

    protected $fillable = [
        'vehicle_id',
        'data',
        'tipus',
        'import',
        'km_totals',
        'taller',
        'motiu',
        'moviment_id',
        'material_id',
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

    /** La peça de la bici a què correspon, si n'és d'una. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(MaterialEsportiu::class, 'material_id');
    }

    /**
     * El que d'un moviment ja han pres les despeses, sense comptar-ne una (la que s'està editant).
     */
    public static function assignatDe(int $movimentId, ?int $excepte = null): float
    {
        return round((float) self::where('moviment_id', $movimentId)
            ->when($excepte, fn ($q) => $q->whereKeyNot($excepte))
            ->sum('import'), 2);
    }

    public function moviment(): BelongsTo
    {
        return $this->belongsTo(MovimentCompteCorrent::class, 'moviment_id');
    }
}
