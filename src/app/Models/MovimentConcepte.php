<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MovimentConcepte extends Model
{
    protected $table = 'g_moviments_conceptes';

    protected $fillable = [
        'concepte',
    ];

    /**
     * Get the movements for this concept.
     */
    public function moviments(): HasMany
    {
        return $this->hasMany(MovimentCompteCorrent::class, 'concepte_id');
    }

    /**
     * Find or create a concept by its text.
     *
     * @param string $concepte
     * @return MovimentConcepte
     */
    public static function findOrCreateByConcepte(string $concepte): self
    {
        return self::firstOrCreate(['concepte' => $concepte]);
    }
}
