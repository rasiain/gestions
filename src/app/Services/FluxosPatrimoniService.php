<?php

namespace App\Services;

use App\Models\AportacioFons;
use App\Models\AportacioPlaPensions;
use App\Models\CompteCorrent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quants diners entren i quants en surten, mes a mes.
 *
 * El total mobiliari és un **estoc** i això són **fluxos**: per això van a una gràfica a
 * part i no barrejats amb els vuit-cents mil euros del total, que no deixarien veure els
 * cinquanta mil d'un mes.
 *
 * El problema de comptar-los és que **un traspàs entre dos comptes propis no és ni un
 * ingrés ni una despesa**: els vuit-cents mil en brut del 2026 inclouen els 80.000 € que
 * van del compte al fons, que surten d'un lloc teu i entren en un altre. La tria de la
 * pantalla és una **caixa**: només compta el que en creua la frontera, i un traspàs entre
 * dos comptes marcats no la creua. Com que quins comptes hi ha dins ho decideix la
 * pantalla, aquí se n'envien els **parells** i és ella qui els anul·la o no.
 *
 * Els parells van amb les **claus de posició** de la pantalla (`comptes-12`, `fons-3`)
 * perquè el traspàs més important no és entre dos comptes: és el que se'n va del compte a
 * un fons, i el fons no té cap moviment bancari on trobar-hi l'altra meitat. Aquell costat
 * es busca a les aportacions.
 */
class FluxosPatrimoniService
{
    /** Dies de marge per considerar que dos moviments oposats són el mateix traspàs. */
    private const DIES_DE_MARGE = 5;

    /**
     * Els fluxos de cada compte i mes, i els traspassos que podrien ser interns.
     *
     * @return array{fluxos: array<int, array<string, mixed>>, traspassos: array<int, array<string, mixed>>}
     */
    public function calcula(): array
    {
        $comptes = CompteCorrent::where('tipus', 'corrent')->pluck('id')->all();

        $fluxos    = [];
        $perImport = [];

        // Una sola lectura: els fluxos i el material per aparellar surten de la mateixa passada
        DB::table('g_moviments_comptes_corrents')
            ->select('id', 'compte_corrent_id', 'data_moviment', 'import')
            ->whereIn('compte_corrent_id', $comptes)
            ->orderBy('data_moviment')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $m) use (&$fluxos, &$perImport): void {
                $mes    = substr((string) $m->data_moviment, 0, 7);
                $import = (float) $m->import;
                $clau   = $m->compte_corrent_id . '|' . $mes;

                $fluxos[$clau] ??= [
                    'posicio'  => 'comptes-' . $m->compte_corrent_id,
                    'mes'      => $mes,
                    'entrades' => 0.0,
                    'sortides' => 0.0,
                ];

                // Les sortides es desen en positiu: la gràfica les gira, els números no
                $fluxos[$clau][$import >= 0 ? 'entrades' : 'sortides'] += abs($import);

                if ($import != 0.0) {
                    $perImport[number_format(abs($import), 2, '.', '')][] = $m;
                }
            });

        return [
            'fluxos'     => array_values(array_map(
                fn (array $f) => [...$f, 'entrades' => round($f['entrades'], 2), 'sortides' => round($f['sortides'], 2)],
                $fluxos,
            )),
            'traspassos' => [...$this->entreComptes($perImport), ...$this->capALesInversions($perImport)],
        ];
    }

    /**
     * Els parells que semblen un mateix traspàs: mateix import, comptes diferents, pocs dies.
     *
     * Cada moviment s'aparella un sol cop, i els parells s'estableixen pel primer que casa:
     * amb dues transferències iguals la mateixa setmana, quina va amb quina no es pot saber
     * ni fa cap falta, perquè el que importa és quants diners han canviat de lloc.
     *
     * @param  array<string, array<int, object>>  $perImport
     * @return array<int, array<string, mixed>>
     */
    private function entreComptes(array $perImport): array
    {
        $parells = [];

        foreach ($perImport as $grup) {
            if (count($grup) < 2) {
                continue;
            }

            $sortides = array_filter($grup, fn (object $m) => (float) $m->import < 0);
            $entrades = array_filter($grup, fn (object $m) => (float) $m->import > 0);
            $usades   = [];

            foreach ($sortides as $sortida) {
                foreach ($entrades as $i => $entrada) {
                    if (isset($usades[$i]) || $entrada->compte_corrent_id === $sortida->compte_corrent_id) {
                        continue;
                    }

                    $dies = Carbon::parse($sortida->data_moviment)->diffInDays(Carbon::parse($entrada->data_moviment), true);
                    if ($dies > self::DIES_DE_MARGE) {
                        continue;
                    }

                    $usades[$i] = true;
                    $parells[] = [
                        'origen' => 'comptes-' . $sortida->compte_corrent_id,
                        'desti'  => 'comptes-' . $entrada->compte_corrent_id,
                        // El mes és el de la sortida: és quan els diners es mouen
                        'mes'    => substr((string) $sortida->data_moviment, 0, 7),
                        'import' => round(abs((float) $sortida->import), 2),
                    ];
                    break;
                }
            }
        }

        return $parells;
    }

    /**
     * El que se'n va del compte a un fons o a un pla: el traspàs que més enganya.
     *
     * L'altra meitat no és cap moviment —el compte del fons no en té— sinó l'aportació del
     * contracte, i es busca allà pel mateix import i amb el mateix marge de dies.
     *
     * @param  array<string, array<int, object>>  $perImport
     * @return array<int, array<string, mixed>>
     */
    private function capALesInversions(array $perImport): array
    {
        $aportacions = [
            ...AportacioFons::with('contracte')->get()
                ->map(fn ($a) => ['posicio' => 'fons-' . $a->contracte_id, 'data' => $a->data, 'import' => (float) $a->import]),
            ...AportacioPlaPensions::with('contracte')->get()
                ->map(fn ($a) => ['posicio' => 'pensions-' . $a->contracte_id, 'data' => $a->data, 'import' => (float) $a->import]),
        ];

        $parells = [];
        $usats   = [];

        foreach ($aportacions as $aportacio) {
            $clau = number_format(abs($aportacio['import']), 2, '.', '');

            foreach ($perImport[$clau] ?? [] as $moviment) {
                if ((float) $moviment->import >= 0 || isset($usats[$moviment->id])) {
                    continue;
                }

                $dies = $aportacio['data']->diffInDays(Carbon::parse($moviment->data_moviment), true);
                if ($dies > self::DIES_DE_MARGE) {
                    continue;
                }

                $usats[$moviment->id] = true;
                $parells[] = [
                    'origen' => 'comptes-' . $moviment->compte_corrent_id,
                    'desti'  => $aportacio['posicio'],
                    'mes'    => substr((string) $moviment->data_moviment, 0, 7),
                    'import' => round(abs((float) $moviment->import), 2),
                ];
                break;
            }
        }

        return $parells;
    }
}
