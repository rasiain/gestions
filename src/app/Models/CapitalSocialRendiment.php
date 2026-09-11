<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Els interessos que la cooperativa paga per les aportacions.
 *
 * L'import és el **brut**: la retenció que hi practiquen es desa a part, que és com ve al
 * certificat i com es declara.
 */
class CapitalSocialRendiment extends Model
{
    protected $table = 'g_cs_rendiments';

    protected $fillable = ['contracte_id', 'data', 'import', 'retencio', 'moviment_id', 'notes'];

    protected $casts = [
        'data'     => 'date',
        'import'   => 'decimal:2',
        'retencio' => 'decimal:2',
    ];

    /** El que arriba de debò al compte, quan hi arriba. */
    public function getNetAttribute(): float
    {
        return round((float) $this->import - (float) $this->retencio, 2);
    }
}
