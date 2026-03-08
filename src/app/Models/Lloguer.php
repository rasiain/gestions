<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lloguer extends Model
{
    protected $table = 'g_lloguers';

    protected $fillable = [
        'nom',
        'immoble_id',
        'compte_corrent_id',
    ];

    public function immoble(): BelongsTo
    {
        return $this->belongsTo(Immoble::class);
    }

    public function compteCorrent(): BelongsTo
    {
        return $this->belongsTo(CompteCorrent::class);
    }

    public function llogaters(): HasMany
    {
        return $this->hasMany(Llogater::class);
    }
}
