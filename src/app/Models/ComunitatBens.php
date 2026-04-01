<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ComunitatBens extends Model
{
    protected $table = 'g_comunitats_bens';

    protected $fillable = [
        'nom',
        'nif',
    ];

    public function arrendadors(): MorphMany
    {
        return $this->morphMany(Arrendador::class, 'arrendadorable');
    }
}
