<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El que es va atribuir a un comuner: nom i NIF copiats, com a la declaració. */
class Model184Comuner extends Model
{
    protected $table = 'g_184_comuners';

    protected $fillable = [
        'immoble_184_id',
        'persona_id',
        'nom',
        'nif',
        'quota',
        'amortitzacio',
        'rendiment',
        'participacio',
        'retencio',
    ];

    protected $casts = [
        'quota'        => 'decimal:4',
        'amortitzacio' => 'decimal:2',
        'rendiment'    => 'decimal:2',
        'participacio' => 'decimal:4',
        'retencio'     => 'decimal:2',
    ];
}
