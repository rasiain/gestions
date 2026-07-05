<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FonsInversio extends Model
{
    protected $table = 'g_fi_fons';

    protected $fillable = ['nom'];

    public function contractes(): HasMany
    {
        return $this->hasMany(ContracteFons::class, 'fons_id');
    }

    public function valors(): HasMany
    {
        return $this->hasMany(ValorFons::class, 'fons_id');
    }
}
