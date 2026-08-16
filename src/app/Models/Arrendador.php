<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Arrendador extends Model
{
    protected $table = 'g_arrendadors';

    protected $fillable = [
        'arrendadorable_type',
        'arrendadorable_id',
    ];

    public function arrendadorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function contractes(): BelongsToMany
    {
        return $this->belongsToMany(Contracte::class, 'g_arrendador_contracte');
    }
}
