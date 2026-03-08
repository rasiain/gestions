<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Llogater extends Model
{
    protected $table = 'g_llogaters';

    protected $fillable = [
        'nom',
        'cognoms',
        'identificador',
        'lloguer_id',
    ];

    public function lloguer(): BelongsTo
    {
        return $this->belongsTo(Lloguer::class);
    }
}
