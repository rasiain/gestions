<?php

namespace App\Services;

use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\Persona;

/**
 * El que una persona té a 31 de desembre, per a la declaració de patrimoni.
 *
 * El patrimoni es valora **el darrer dia de l'any**, no avui: el saldo que compta és el
 * que hi havia el 31 de desembre, i per això no es pren el saldo actual del compte sinó
 * el `saldo_posterior` del darrer moviment de l'any — que és el que diu el banc i ja
 * s'ha validat en importar.
 *
 * De moment només l'apartat E de béns i drets. La resta d'apartats hi aniran a sobre.
 */
class PatrimoniService
{
    /** Apartat de béns i drets que avui es calcula. */
    public const APARTAT_DIPOSITS = 'E';

    /**
     * La declaració d'una persona per a un exercici.
     *
     * @return array<string, mixed>
     */
    public function declaracio(Persona $persona, int $any): array
    {
        $diposits = $this->diposits($persona, $any);

        $totalBens   = round((float) array_sum(array_column($diposits, 'part')), 2);
        $totalDeutes = 0.0;

        return [
            'persona' => [
                'id'  => $persona->id,
                'nom' => trim($persona->nom . ' ' . $persona->cognoms),
                'nif' => $persona->nif,
            ],
            'any'  => $any,
            'data' => sprintf('%04d-12-31', $any),
            'bens' => [
                'apartats' => [
                    [
                        'clau'    => self::APARTAT_DIPOSITS,
                        'titol'   => "Dipòsits en compte corrent o d'estalvi, a la vista o a termini, comptes financers i altres tipus d'imposicions en compte",
                        'comptes' => $diposits,
                        'total'   => $totalBens,
                    ],
                ],
                'total' => $totalBens,
            ],
            'deutes' => [
                'apartats' => [],
                'total'    => $totalDeutes,
            ],
            'total' => round($totalBens - $totalDeutes, 2),
        ];
    }

    /**
     * Apartat E: els comptes corrents de la persona, amb el saldo del 31 de desembre i la
     * part que li pertoca.
     *
     * Només els comptes corrents —personals i de lloguer—: els d'inversió no són dipòsits
     * i van al seu propi apartat de la declaració, que encara no s'ha fet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function diposits(Persona $persona, int $any): array
    {
        $comptes = CompteCorrent::where('tipus', 'corrent')
            ->whereHas('titulars', fn ($q) => $q->where('g_persones.id', $persona->id))
            ->with('titulars')
            ->orderBy('ordre')
            ->get();

        $fi = sprintf('%04d-12-31', $any);

        return $comptes->map(function (CompteCorrent $compte) use ($persona, $any, $fi) {
            $saldo    = $this->saldoAData($compte, $fi);
            $mitjana  = $this->saldoMitjaDarrerTrimestre($compte, $any);
            // La llei demana el més gran dels dos
            $valor    = max($saldo, $mitjana);
            $titulars = max($compte->titulars->count(), 1);

            return [
                'compte_corrent_id' => $compte->id,
                'nom'               => $compte->nom ?? $compte->compte_corrent,
                'digits'            => substr((string) $compte->compte_corrent, -4),
                'entitat'           => $compte->entitat,
                'saldo'             => $saldo,
                'saldo_mig'         => $mitjana,
                'valor'             => $valor,
                'criteri'           => $mitjana > $saldo ? 'mitjana' : 'saldo',
                'titulars'          => $titulars,
                // A parts iguals, com a la resta de l'aplicació: el pivot de titulars no
                // desa cap percentatge.
                'part'              => round($valor / $titulars, 2),
                'altres_titulars'   => $compte->titulars
                    ->reject(fn ($t) => $t->id === $persona->id)
                    ->map(fn ($t) => trim($t->nom . ' ' . $t->cognoms))
                    ->values()
                    ->all(),
            ];
        })->all();
    }

    /**
     * Saldo mitjà del darrer trimestre de l'any (1 d'octubre a 31 de desembre).
     *
     * És una mitjana **ponderada pels dies**, no la mitjana dels saldos que apareixen a
     * l'extracte: un saldo que dura dos mesos pesa més que un que dura un dia. Es parteix
     * del saldo amb què s'arriba al trimestre i es va actualitzant a cada moviment.
     *
     * La llei permet descomptar de la mitjana el que s'hagi fet servir per comprar altres
     * béns o cancel·lar deutes; això no s'aplica sol, l'ha de decidir qui declara.
     */
    public function saldoMitjaDarrerTrimestre(CompteCorrent $compte, int $any): float
    {
        $inici = new \DateTimeImmutable(sprintf('%04d-10-01', $any));
        $fi    = new \DateTimeImmutable(sprintf('%04d-12-31', $any));

        // El saldo amb què s'entra al trimestre
        $saldo = $this->saldoAData($compte, sprintf('%04d-09-30', $any));

        // L'últim saldo de cada dia amb moviment: dins d'un mateix dia només compta el final
        $perDia = MovimentCompteCorrent::where('compte_corrent_id', $compte->id)
            // whereDate i no whereBetween: la data es desa com a «Y-m-d 00:00:00» i una
            // comparació de text es menjaria tots els moviments del darrer dia.
            ->whereDate('data_moviment', '>=', $inici->format('Y-m-d'))
            ->whereDate('data_moviment', '<=', $fi->format('Y-m-d'))
            ->whereNotNull('saldo_posterior')
            ->orderBy('data_moviment')
            ->orderBy('id')
            ->get(['data_moviment', 'saldo_posterior'])
            ->groupBy(fn (MovimentCompteCorrent $m) => $m->data_moviment->format('Y-m-d'))
            ->map(fn ($dia) => (float) $dia->last()->saldo_posterior);

        $suma = 0.0;
        $dies = 0;

        for ($dia = $inici; $dia <= $fi; $dia = $dia->modify('+1 day')) {
            $saldo = $perDia->get($dia->format('Y-m-d'), $saldo);
            $suma += $saldo;
            $dies++;
        }

        return $dies > 0 ? round($suma / $dies, 2) : 0.0;
    }

    /**
     * Saldo del compte al final del dia indicat.
     *
     * Del `saldo_posterior` del darrer moviment, no de sumar imports: és el que diu
     * l'extracte del banc, que és el que s'ha de declarar.
     */
    public function saldoAData(CompteCorrent $compte, string $data): float
    {
        $saldo = MovimentCompteCorrent::where('compte_corrent_id', $compte->id)
            // whereDate: la data es desa com a «Y-m-d 00:00:00» i un «<= Y-m-d» de text
            // deixaria fora els moviments del dia mateix.
            ->whereDate('data_moviment', '<=', $data)
            ->whereNotNull('saldo_posterior')
            ->orderByDesc('data_moviment')
            ->orderByDesc('id')
            ->value('saldo_posterior');

        return $saldo !== null ? round((float) $saldo, 2) : 0.0;
    }
}
