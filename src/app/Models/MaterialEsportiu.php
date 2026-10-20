<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una peça de material esportiu tal com la porta l'aplicació d'on ve (Garmin…). Quan és
 * una bici, apunta al vehicle de l'aplicació.
 */
class MaterialEsportiu extends Model
{
    protected $table = 'g_material_esportiu';

    public const TIPUS = ['bici', 'sabates', 'component', 'altres'];

    public const ESTATS = ['actiu', 'retirat'];

    protected $fillable = [
        'origen',
        'id_extern',
        'tipus',
        'nom',
        'model',
        'estat',
        'data_inici',
        'data_fi',
        'vehicle_id',
        'editat',
        'muntat_a_vehicle_id',
        'dades_originals',
    ];

    protected function casts(): array
    {
        return [
            'data_inici'      => 'date',
            'data_fi'         => 'date',
            'dades_originals' => 'array',
            'editat'          => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** La bici on va muntat (només els components). */
    public function muntatA(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'muntat_a_vehicle_id');
    }

    public function sortides(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SortidaBici::class, 'g_sortida_material', 'material_id', 'sortida_id');
    }
}
