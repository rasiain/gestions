<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaPensions extends Model
{
    protected $table = 'g_pp_plans';

    protected $fillable = ['nom'];

    public function contractes(): HasMany
    {
        return $this->hasMany(ContractePlaPensions::class, 'pla_id');
    }

    public function valors(): HasMany
    {
        return $this->hasMany(ValorPlaPensions::class, 'pla_id');
    }
}
