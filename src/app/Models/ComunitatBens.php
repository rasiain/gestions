<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ComunitatBens extends Model
{
    protected $table = 'g_comunitats_bens';

    protected $fillable = [
        'nom',
        'nif',
        'adreca',
        'activitat',
        'codi_activitat',
        'epigraf_iae',
    ];

    protected $casts = [
        'epigraf_iae' => 'integer',
    ];

    public function arrendadors(): MorphMany
    {
        return $this->morphMany(Arrendador::class, 'arrendadorable');
    }

    public function comuners(): BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'g_comunitat_bens_comuner', 'comunitat_bens_id', 'persona_id')
            ->withTimestamps();
    }
}
