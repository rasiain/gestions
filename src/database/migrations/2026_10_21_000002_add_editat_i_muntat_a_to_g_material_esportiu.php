<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - `editat`: la peça s'ha corregit a mà (nom, model, tipus, estat o dates). Una
     *   importació posterior n'actualitza el registre original però no aquestes dades.
     * - `muntat_a_vehicle_id`: la bici on va muntat un component. No és el `vehicle_id` de
     *   la taula, que diu quina bici del catàleg *és* la peça (només a les de tipus bici).
     *
     * Els components que ja hi ha s'hi munten a la bici d'almenys el 90 % de les seves
     * sortides: les poques que en tenen una altra són marques errònies a Garmin. Les sabates,
     * el casc o la cinta del pulsòmetre van amb la persona, no amb la bici, i no en tenen.
     */
    public function up(): void
    {
        Schema::table('g_material_esportiu', function (Blueprint $table) {
            $table->boolean('editat')->default(false)->after('vehicle_id');
            $table->foreignId('muntat_a_vehicle_id')->nullable()->after('editat')
                ->constrained('g_vehicles')->nullOnDelete();
        });

        $usos = DB::table('g_sortida_material')
            ->join('g_sortides_bici', 'g_sortides_bici.id', '=', 'g_sortida_material.sortida_id')
            ->join('g_material_esportiu', 'g_material_esportiu.id', '=', 'g_sortida_material.material_id')
            ->where('g_material_esportiu.tipus', 'component')
            ->selectRaw('g_sortida_material.material_id, g_sortides_bici.vehicle_id, count(*) as n')
            ->groupBy('g_sortida_material.material_id', 'g_sortides_bici.vehicle_id')
            ->get()
            ->groupBy('material_id');

        foreach ($usos as $materialId => $perBici) {
            $total = $perBici->sum('n');
            $principal = $perBici->sortByDesc('n')->first();

            if ($principal->n / $total >= 0.9) {
                DB::table('g_material_esportiu')->where('id', $materialId)
                    ->update(['muntat_a_vehicle_id' => $principal->vehicle_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('g_material_esportiu', function (Blueprint $table) {
            $table->dropConstrainedForeignId('muntat_a_vehicle_id');
            $table->dropColumn('editat');
        });
    }
};
