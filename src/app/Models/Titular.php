<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Titular extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'g_titulars';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom',
        'cognoms',
    ];

    /**
     * Get the comptes corrents associated with this titular.
     */
    public function comptesCorrents()
    {
        return $this->belongsToMany(CompteCorrent::class, 'g_compte_corrent_titular', 'titular_id', 'compte_corrent_id')
            ->withTimestamps();
    }
}
