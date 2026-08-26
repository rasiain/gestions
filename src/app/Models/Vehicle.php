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

    protected $fillable = [
        'nom',
        'tipus',
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

    /** Un vehicle donat de baixa continua al catàleg, però ja no és nostre. */
    public function esActiu(): bool
    {
        return $this->data_baixa === null || $this->data_baixa->isFuture();
    }
}
