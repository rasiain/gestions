<?php

use App\Models\MovimentCompteCorrent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $compteIds = DB::table('g_moviments_comptes_corrents')
            ->distinct()
            ->pluck('compte_corrent_id');

        foreach ($compteIds as $compteCorrentId) {
            $moviments = DB::table('g_moviments_comptes_corrents')
                ->where('compte_corrent_id', $compteCorrentId)
                ->orderBy('data_moviment', 'asc')
                ->orderBy('id', 'asc')
                ->get(['id', 'data_moviment', 'concepte_original', 'import']);

            $sequenceCounters = [];

            foreach ($moviments as $moviment) {
                $dateNormalized = substr($moviment->data_moviment, 0, 10);
                $key = sprintf('%s|%.2f', $dateNormalized, $moviment->import);

                if (!isset($sequenceCounters[$key])) {
                    $sequenceCounters[$key] = 0;
                }

                $newHash = MovimentCompteCorrent::generateHash(
                    $dateNormalized,
                    $moviment->concepte_original ?? '',
                    (float) $moviment->import,
                    $compteCorrentId,
                    $sequenceCounters[$key]
                );

                DB::table('g_moviments_comptes_corrents')
                    ->where('id', $moviment->id)
                    ->update(['hash' => $newHash]);

                $sequenceCounters[$key]++;
            }

            Log::info('Recalculated hashes for compte corrent', [
                'compte_corrent_id' => $compteCorrentId,
                'total_moviments' => count($moviments),
            ]);
        }
    }

    public function down(): void
    {
        // Cannot revert to old hashes
    }
};
