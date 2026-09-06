<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un vehicle del catàleg: cotxe, moto o bicicleta.
 */
class Vehicle extends Model
{
    protected $table = 'g_vehicles';

    /** Tipus admesos. La bici hi és perquè també té despeses i quilòmetres. */
    public const TIPUS = ['cotxe', 'moto', 'bici', 'altres'];

    /** Combustibles admesos. Buit a les bicis i a qualsevol cosa que no reposti. */
    public const COMBUSTIBLES = ['dièsel', 'gasolina', 'elèctric', 'híbrid', 'GLP'];

    protected $fillable = [
        'nom',
        'tipus',
        'combustible',
        'marca',
        'model',
        'matricula',
        'any_fabricacio',
        'data_alta',
        'data_baixa',
        'notes',
        'ordre',
    ];

    protected $casts = [
        'any_fabricacio' => 'integer',
        'data_alta'      => 'date',
        'data_baixa'     => 'date',
        'ordre'          => 'integer',
    ];

    public function repostatges(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleRepostatge::class, 'vehicle_id');
    }

    public function despeses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleDespesa::class, 'vehicle_id');
    }

    /** Un vehicle donat de baixa continua al catàleg, però ja no és nostre. */
    public function esActiu(): bool
    {
        return $this->data_baixa === null || $this->data_baixa->isFuture();
    }
}
