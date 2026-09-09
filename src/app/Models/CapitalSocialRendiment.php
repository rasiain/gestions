<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Els interessos que la cooperativa paga per les aportacions. */
class CapitalSocialRendiment extends Model
{
    protected $table = 'g_cs_rendiments';

    protected $fillable = ['contracte_id', 'data', 'import', 'moviment_id', 'notes'];

    protected $casts = [
        'data'   => 'date',
        'import' => 'decimal:2',
    ];
}
