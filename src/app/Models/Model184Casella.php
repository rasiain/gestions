<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Import declarat en una de les deu caselles de despesa. */
class Model184Casella extends Model
{
    protected $table = 'g_184_caselles';

    protected $fillable = ['immoble_184_id', 'casella', 'import'];

    protected $casts = [
        'casella' => 'integer',
        'import'  => 'decimal:2',
    ];
}
