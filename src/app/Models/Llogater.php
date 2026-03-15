<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Llogater extends Model
{
    protected $table = 'g_llogaters';

    protected $fillable = [
        'nom',
        'cognoms',
        'identificador',
    ];

    public function contractes(): BelongsToMany
    {
        return $this->belongsToMany(Contracte::class, 'g_contracte_llogater');
    }
}
