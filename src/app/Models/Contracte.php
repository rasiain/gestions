<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contracte extends Model
{
    protected $table = 'g_contractes';

    protected $fillable = [
        'data_inici',
        'data_fi',
    ];

    protected $casts = [
        'data_inici' => 'date',
        'data_fi'    => 'date',
    ];
}
