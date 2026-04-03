<?php

namespace App\Services;

use App\Models\MovimentCompteCorrent;

class SaldoRecalculationService
{
    /**
     * Recalcula els saldos des del punt indicat fins al final del compte.
     *
     * @param int $compteCorrentId
     * @param string $dataMoviment Data a partir de la qual recalcular (format Y-m-d)
     * @param int|null $movimentId Si s'indica, el punt d'inici és data >= $dataMoviment,
     *                             o (data == $dataMoviment AND id >= $movimentId)
     */
    public function recalcularDesde(int $compteCorrentId, string $dataMoviment, ?int $movimentId = null): void
    {
        // 1. Troba el saldo_posterior de l'últim moviment ANTERIOR al punt indicat
        $query = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId);

        if ($movimentId !== null) {
            // Anterior: (data < data) OR (data == data AND id < movimentId)
            $query->where(function ($q) use ($dataMoviment, $movimentId) {
                $q->where('data_moviment', '<', $dataMoviment)
                  ->orWhere(function ($q2) use ($dataMoviment, $movimentId) {
                      $q2->where('data_moviment', '=', $dataMoviment)
                         ->where('id', '<', $movimentId);
                  });
            });
        } else {
            $query->where('data_moviment', '<', $dataMoviment);
        }

        $anterior = $query->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $saldoBase = $anterior?->saldo_posterior ?? 0;

        // 2. Selecciona tots els moviments des del punt indicat endavant
        $queryEndavant = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId);

        if ($movimentId !== null) {
            $queryEndavant->where(function ($q) use ($dataMoviment, $movimentId) {
                $q->where('data_moviment', '>', $dataMoviment)
                  ->orWhere(function ($q2) use ($dataMoviment, $movimentId) {
                      $q2->where('data_moviment', '=', $dataMoviment)
                         ->where('id', '>=', $movimentId);
                  });
            });
        } else {
            $queryEndavant->where('data_moviment', '>=', $dataMoviment);
        }

        // 3. Processa per chunks per evitar problemes de memòria
        $queryEndavant->orderBy('data_moviment', 'asc')
            ->orderBy('id', 'asc')
            ->chunk(100, function ($moviments) use (&$saldoBase) {
                foreach ($moviments as $moviment) {
                    $saldoBase = round($saldoBase + $moviment->import, 2);
                    $moviment->saldo_posterior = $saldoBase;
                    $moviment->saveQuietly();
                }
            });
    }

    /**
     * Recalcula els saldos després d'una actualització que pot canviar data o compte.
     *
     * @param MovimentCompteCorrent $moviment El moviment ja actualitzat
     * @param string $dataAnterior La data abans de l'actualització
     * @param int $compteAnteriorId El compte_corrent_id abans de l'actualització
     */
    public function recalcularPerUpdate(
        MovimentCompteCorrent $moviment,
        string $dataAnterior,
        int $compteAnteriorId
    ): void {
        $dataNova = $moviment->data_moviment->format('Y-m-d');
        $compteNouId = $moviment->compte_corrent_id;

        if ($compteAnteriorId !== $compteNouId) {
            // Ha canviat el compte: recalcular ambdós comptes
            $this->recalcularDesde($compteAnteriorId, $dataAnterior);
            $this->recalcularDesde($compteNouId, $dataNova, $moviment->id);
        } else {
            // Mateix compte: recalcular des del punt més antic
            $dataMinima = $dataAnterior <= $dataNova ? $dataAnterior : $dataNova;

            if ($dataAnterior === $dataNova) {
                // La data no ha canviat: recalcular des del moviment (pot haver canviat l'import)
                $this->recalcularDesde($compteNouId, $dataNova, $moviment->id);
            } else {
                // La data ha canviat: recalcular des de la mínima sense filtre de id
                $this->recalcularDesde($compteNouId, $dataMinima);
            }
        }
    }
}
