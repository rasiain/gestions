<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleConnexio extends Model
{
    protected $table = 'g_google_connexions';

    protected $fillable = [
        'email',
        'refresh_token',
        'access_token',
        'access_token_expira',
    ];

    protected $hidden = [
        'refresh_token',
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token'       => 'encrypted',
            'access_token'        => 'encrypted',
            'access_token_expira' => 'datetime',
        ];
    }
}
