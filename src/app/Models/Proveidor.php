<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveidor extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_proveidors';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom_rao_social',
        'nif_cif',
        'adreca',
        'correu_electronic',
        'telefons',
    ];
}
