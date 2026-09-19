<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tram d'administració d'un immoble: quina empresa l'administrava, amb quin
 * identificador el coneixia i quina comissió cobrava, entre dues dates.
 */
class AdministracioImmoble extends Model
{
    protected $table = 'g_administracions_immobles';

    protected $fillable = [
        'immoble_id',
        'proveidor_id',
        'referencia',
        'percentatge',
        'data_inici',
        'data_fi',
    ];

    protected $casts = [
        'percentatge' => 'decimal:2',
        'data_inici'  => 'date',
        'data_fi'     => 'date',
    ];

    public function immoble(): BelongsTo
    {
        return $this->belongsTo(Immoble::class);
    }

    public function proveidor(): BelongsTo
    {
        return $this->belongsTo(Proveidor::class);
    }

    public function vigentA(CarbonInterface|string $data): bool
    {
        $dia = $data instanceof CarbonInterface ? $data->toDateString() : substr($data, 0, 10);

        return ($this->data_inici === null || $this->data_inici->toDateString() <= $dia)
            && ($this->data_fi === null || $this->data_fi->toDateString() >= $dia);
    }

    /**
     * Com l'envien les pantalles.
     *
     * @return array<string, mixed>
     */
    public function perAlClient(): array
    {
        return [
            'id'           => $this->id,
            'proveidor_id' => $this->proveidor_id,
            'proveidor'    => $this->proveidor?->nom_rao_social,
            'referencia'   => $this->referencia,
            'percentatge'  => $this->percentatge,
            'data_inici'   => $this->data_inici?->toDateString(),
            'data_fi'      => $this->data_fi?->toDateString(),
        ];
    }
}
