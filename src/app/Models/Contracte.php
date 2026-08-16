<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Arrendador;

class Contracte extends Model
{
    protected $table = 'g_contractes';

    protected $fillable = [
        'lloguer_id',
        'data_inici',
        'data_fi',
    ];

    protected $casts = [
        'data_inici' => 'date',
        'data_fi'    => 'date',
    ];

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }

    public function llogaters(): BelongsToMany
    {
        return $this->belongsToMany(Llogater::class, 'g_contracte_llogater');
    }

    /**
     * Un contracte pot tenir més d'un arrendador quan els copropietaris
     * arrenden en proindivís.
     */
    public function arrendadors(): BelongsToMany
    {
        return $this->belongsToMany(Arrendador::class, 'g_arrendador_contracte');
    }
}
