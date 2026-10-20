<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Una sortida en bici. El resum és aquí; els punts, a la BD `recorreguts`.
 */
class SortidaBici extends Model
{
    protected $table = 'g_sortides_bici';

    protected $guarded = ['id'];

    /** Els valors per defecte de la taula, perquè un model acabat de crear ja els porti. */
    protected $attributes = [
        'punts'              => 0,
        'desnivell_corregit' => false,
    ];

    protected function casts(): array
    {
        return [
            'inici'              => 'datetime',
            'data_local'         => 'date',
            'desnivell_corregit' => 'boolean',
            'resum_dispositiu'   => 'array',
            'dades_originals'    => 'array',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function material(): BelongsToMany
    {
        return $this->belongsToMany(MaterialEsportiu::class, 'g_sortida_material', 'sortida_id', 'material_id');
    }

    /** Els punts del recorregut, en l'ordre en què es van gravar. */
    public function puntsRecorregut()
    {
        return DB::connection('recorreguts')->table('punts')->where('sortida_id', $this->id)->orderBy('t');
    }
}
