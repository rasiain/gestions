<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Llogater extends Model
{
    protected $table = 'g_llogaters';

    protected $fillable = [
        'tipus',
        'persona_id',
        'nom_rao_social',
        'nif',
        'adreca',
        'codi_postal',
        'poblacio',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function contractes(): BelongsToMany
    {
        return $this->belongsToMany(Contracte::class, 'g_contracte_llogater');
    }

    public function nomDisplay(): string
    {
        if ($this->tipus === 'persona' && $this->persona) {
            return trim($this->persona->nom . ' ' . $this->persona->cognoms);
        }
        return $this->nom_rao_social ?? '';
    }

    public function cognomsDisplay(): string
    {
        if ($this->tipus === 'persona' && $this->persona) {
            return $this->persona->cognoms ?? '';
        }
        return '';
    }

    public function nifDisplay(): string
    {
        if ($this->tipus === 'persona' && $this->persona) {
            return $this->persona->nif ?? '';
        }
        return $this->nif ?? '';
    }
}
