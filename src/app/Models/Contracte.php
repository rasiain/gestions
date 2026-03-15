<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
}
