<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entitat extends Model
{
    protected $table = 'g_entitats';

    protected $fillable = ['nom'];

    public function comptesCorrents(): HasMany
    {
        return $this->hasMany(CompteCorrent::class, 'entitat_id');
    }

    public static function firstOrCreateByNom(string $nom): self
    {
        return self::firstOrCreate(['nom' => $nom]);
    }
}
