<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxaPatro extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_taxes_patrons';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'etiqueta',
        'patro',
        'actiu',
        'ordre',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'actiu' => 'boolean',
        'ordre' => 'integer',
    ];
}
