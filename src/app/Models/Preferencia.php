<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una preferència de l'aplicació, per clau (p. ex. `bicis.altitud`), amb el valor en JSON. */
class Preferencia extends Model
{
    protected $table = 'g_preferencies';

    protected $primaryKey = 'clau';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['valor' => 'array'];
    }

    public static function valor(string $clau, mixed $defecte = null): mixed
    {
        return static::find($clau)?->valor ?? $defecte;
    }

    public static function desa(string $clau, mixed $valor): void
    {
        static::updateOrCreate(['clau' => $clau], ['valor' => $valor]);
    }
}
