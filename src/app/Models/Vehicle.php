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

    /** Modalitats de bici. Els rodets hi són a part perquè els seus quilòmetres són virtuals. */
    public const MODALITATS = [
        'btt'       => 'BTT',
        'carretera' => 'Carretera',
        'gravel'    => 'Gravel',
        'urbana'    => 'Urbana',
        'rodets'    => 'Rodets',
    ];

    /** Per què ja no compta: la data de baixa diu quan, no per què. */
    public const MOTIUS_BAIXA = [
        'venut'   => 'Venut',
        'retirat' => 'Retirat (encara el tinc)',
        'robat'   => 'Robat',
        'regalat' => 'Regalat',
        'altres'  => 'Altres',
    ];

    protected $fillable = [
        'nom',
        'tipus',
        'combustible',
        'modalitat',
        'marca',
        'model',
        'matricula',
        'any_fabricacio',
        'data_alta',
        'data_baixa',
        'motiu_baixa',
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

    /** El material de l'aplicació d'origen (Garmin…) que correspon a aquest vehicle. */
    public function material(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaterialEsportiu::class, 'vehicle_id');
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
