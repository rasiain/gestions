<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El lloguer «RUTLLA 11 2-2» apuntava a l'aparcament de la planta −1 (34 m²,
 * magatzem/estacionament) i no al pis (122 m², residencial). El contracte inclou tots
 * dos, però un lloguer només pot vincular un immoble i el principal és el pis: és el que
 * porta la referència cadastral a declarar i el que agrupa taxes i assegurances.
 */
return new class extends Migration
{
    private const PIS         = '5475506DG8457E0032OB';
    private const APARCAMENT  = '5475506DG8457E0006GE';
    private const ACRONIM     = 'R11';

    public function up(): void
    {
        $this->mou(self::APARCAMENT, self::PIS);
        // La comissió era al tram de l'aparcament, perquè era ell qui tenia el lloguer
        $this->mouLaComissio(self::APARCAMENT, self::PIS);
    }

    public function down(): void
    {
        $this->mou(self::PIS, self::APARCAMENT);
        $this->mouLaComissio(self::PIS, self::APARCAMENT);
    }

    /**
     * La comissió de l'administradora és la del lloguer i va amb ell; la referència, en
     * canvi, es queda a tots dos immobles, que Finques Saura els administra tots dos.
     */
    private function mouLaComissio(string $desDe, string $cap): void
    {
        $origen = $this->tram($desDe);
        $desti  = $this->tram($cap);

        if (!$origen || !$desti || $origen->percentatge === null) {
            return;
        }

        DB::table('g_administracions_immobles')->where('id', $desti->id)->update([
            'percentatge' => $origen->percentatge,
            'updated_at'  => now(),
        ]);

        DB::table('g_administracions_immobles')->where('id', $origen->id)->update([
            'percentatge' => null,
            'updated_at'  => now(),
        ]);
    }

    private function tram(string $referenciaCadastral): ?object
    {
        return DB::table('g_administracions_immobles')
            ->join('g_immobles', 'g_immobles.id', '=', 'g_administracions_immobles.immoble_id')
            ->where('g_immobles.referencia_cadastral', $referenciaCadastral)
            ->whereNull('g_administracions_immobles.data_fi')
            ->select('g_administracions_immobles.id', 'g_administracions_immobles.percentatge')
            ->first();
    }

    private function mou(string $desDe, string $cap): void
    {
        $origen = DB::table('g_immobles')->where('referencia_cadastral', $desDe)->value('id');
        $desti  = DB::table('g_immobles')->where('referencia_cadastral', $cap)->value('id');

        if (!$origen || !$desti) {
            return;
        }

        DB::table('g_lloguers')
            ->where('acronim', self::ACRONIM)
            ->where('immoble_id', $origen)
            ->update(['immoble_id' => $desti]);
    }
};
