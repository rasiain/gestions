<?php

namespace App\Services;

use App\Models\SortidaBici;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * L'estat de forma a partir de les sortides en bici: la càrrega de cada sortida i, dia a dia, les
 * corbes de fons, fatiga i frescor (el model de Banister en la forma de TrainingPeaks i Strava).
 *
 * **La càrrega és el TRIMP d'Edwards**: minuts a cada zona de pulsació × el número de la zona (1…5),
 * amb les zones per percentatge de la pulsació màxima vigent aquell dia. Es tria aquest perquè només
 * necessita la pulsació màxima, que es dedueix de les dades. Es calcula, per ordre de preferència:
 *
 * - `punts`: sobre les pulsacions de cada punt del recorregut (cada punt compta fins al següent, amb un
 *   màxim de 10 s: un forat més llarg és una pausa);
 * - `zones`: amb el temps a cada zona que diu Garmin, que fa servir les seves zones i no les nostres;
 * - `mitjana`: tota la sortida a la zona de la pulsació mitjana, quan no hi ha res més.
 *
 * No es desa enlloc: es pot reconstruir (llegir els punts de totes les sortides des de 2024 triga una
 * fracció de segon) i depèn de les dades físiques, que es poden corregir.
 *
 * **Només hi ha les sortides en bici**: la natació, les caminades i la resta no hi són, i sense elles la
 * càrrega i el fons surten més baixos, i la frescor, més alta.
 */
class EstatFormaService
{
    /** Des de quan es mostra (abans no interessa). */
    public const DES_DE = '2024-01-01';

    /** Des de quan es calcula: tres mesos abans, perquè el fons hi arribi ja escalfat. */
    public const ESCALFAMENT = '2023-10-01';

    /** Constants de temps del fons i de la fatiga, en dies. */
    public const DIES_FONS = 42;

    public const DIES_FATIGA = 7;

    /** Un forat més llarg entre dos punts és una pausa: només en compta això. */
    public const MAXIM_SEGONS_PUNT = 10;

    /** Una sortida amb més d'aquesta part del temps sense pulsació és parcial. */
    public const PART_SENSE_FC = 0.2;

    /** Finestra de la pulsació sostinguda de la sortida, en segons. */
    public const FINESTRA_SOSTINGUDA = 30;

    public function __construct(private DadesFisiquesService $dades) {}

    /**
     * La càrrega de cada sortida des d'una data, per ordre cronològic.
     *
     * @return array<int, array{id: int, data: string, nom: ?string, bici: ?string, rodets: bool, minuts: ?float, trimp: ?float, zones: ?array<int, float>, fc_maxima: ?array{valor: int, origen: string}, metode: ?string, parcial: bool}>
     */
    public function carregues(CarbonInterface $des, ?CarbonInterface $fins = null): array
    {
        $sortides = SortidaBici::with('vehicle:id,nom')
            ->where('data_local', '>=', $des->toDateString())
            ->when($fins, fn ($q) => $q->where('data_local', '<=', $fins->toDateString()))
            ->orderBy('inici')
            ->get(['id', 'vehicle_id', 'nom', 'tipus_activitat', 'data_local', 'inici', 'fc_mitjana', 'fc_max', 'temps_moviment_s', 'temps_s', 'punts', 'dades_originals']);

        return $sortides->map(fn (SortidaBici $s) => $this->carrega($s))->all();
    }

    /** @return array{id: int, data: string, nom: ?string, bici: ?string, rodets: bool, minuts: ?float, trimp: ?float, zones: ?array<int, float>, fc_maxima: ?array{valor: int, origen: string}, metode: ?string, parcial: bool} */
    public function carrega(SortidaBici $s): array
    {
        $fila = [
            'id'        => $s->id,
            'data'      => $s->data_local->toDateString(),
            'nom'       => $s->nom,
            'bici'      => $s->vehicle?->nom,
            'rodets'    => $s->tipus_activitat === 'indoor_cycling',
            'minuts'    => null,
            'trimp'     => null,
            'zones'     => null,
            'fc_maxima' => null,
            'metode'    => null,
            'parcial'   => false,
        ];

        $fcMaxima = $this->dades->fcMaxima($s->data_local);
        if (! $fcMaxima) {
            return $fila;
        }

        $punts = $s->punts ? DB::connection('recorreguts')->table('punts')->where('sortida_id', $s->id)->orderBy('t')->get(['t', 'fc']) : collect();
        $ambFc = $punts->contains(fn ($p) => $p->fc > 0);

        if ($ambFc) {
            // Una sortida que la supera de manera sostinguda vol dir que la vigent anava curta
            $sostinguda = $this->sostinguda($punts->all());
            if ($sostinguda > $fcMaxima['valor']) {
                $fcMaxima = ['valor' => $sostinguda, 'origen' => 'sortida'];
            }
            [$zones, $segons, $senseFc] = $this->zonesDePunts($punts->all(), $fcMaxima['valor']);
            $fila['metode'] = 'punts';
            $fila['parcial'] = $segons > 0 && $senseFc / $segons > self::PART_SENSE_FC;
            $fila['minuts'] = round($segons / 60, 1);
        } elseif ($zonesGarmin = $this->zonesDeGarmin($s->dades_originals ?? [])) {
            $zones = $zonesGarmin;
            $fila['metode'] = 'zones';
            $fila['minuts'] = round(array_sum($zones), 1);
        } elseif ($s->fc_mitjana) {
            $minuts = ($s->temps_moviment_s ?: $s->temps_s) / 60;
            $zones = array_fill(1, 5, 0.0);
            $z = $this->zona($s->fc_mitjana, $fcMaxima['valor']);
            if ($z) {
                $zones[$z] = $minuts;
            }
            $fila['metode'] = 'mitjana';
            $fila['minuts'] = round($minuts, 1);
        } else {
            return $fila;
        }

        $fila['fc_maxima'] = $fcMaxima;
        $fila['zones'] = array_map(fn ($m) => round($m, 1), $zones);
        $fila['trimp'] = round(array_sum(array_map(fn ($m, $z) => $m * $z, $zones, array_keys($zones))), 1);

        return $fila;
    }

    /**
     * Fons, fatiga i frescor de cada dia entre dues dates. La càrrega del dia és la suma de les seves
     * sortides; els dies sense, 0. La frescor d'un dia és la del matí: fons − fatiga del dia d'abans.
     *
     * @param  array<int, array{data: string, trimp: ?float}>  $carregues  des d'abans de `$mostraDes` (l'escalfament)
     * @return array<int, array{data: string, carrega: float, fons: float, fatiga: float, frescor: float}>
     */
    public function corbes(array $carregues, CarbonInterface $des, CarbonInterface $mostraDes, CarbonInterface $fins): array
    {
        $perDia = [];
        foreach ($carregues as $c) {
            $perDia[$c['data']] = ($perDia[$c['data']] ?? 0) + ($c['trimp'] ?? 0);
        }

        $fons = 0.0;
        $fatiga = 0.0;
        $corbes = [];
        for ($dia = Carbon::parse($des); $dia->lte($fins); $dia->addDay()) {
            $clau = $dia->toDateString();
            $carrega = $perDia[$clau] ?? 0.0;
            $frescor = $fons - $fatiga;
            $fons += ($carrega - $fons) / self::DIES_FONS;
            $fatiga += ($carrega - $fatiga) / self::DIES_FATIGA;

            if ($dia->gte($mostraDes)) {
                $corbes[] = ['data' => $clau, 'carrega' => round($carrega, 1), 'fons' => round($fons, 1), 'fatiga' => round($fatiga, 1), 'frescor' => round($frescor, 1)];
            }
        }

        return $corbes;
    }

    /** La zona d'Edwards d'una pulsació: 1 (50–60 %) … 5 (90 % i més); 0 per sota del 50 %. */
    public function zona(float $fc, int $fcMaxima): int
    {
        $part = $fc / $fcMaxima;

        return $part < 0.5 ? 0 : min(5, (int) floor($part * 10 + 1e-9) - 4);
    }

    /**
     * Minuts a cada zona a partir dels punts.
     *
     * @param  array<int, object{t: int, fc: ?int}>  $punts
     * @return array{0: array<int, float>, 1: int, 2: int} minuts per zona, segons comptats, segons sense pulsació
     */
    private function zonesDePunts(array $punts, int $fcMaxima): array
    {
        $zones = array_fill(1, 5, 0.0);
        $segons = 0;
        $senseFc = 0;
        $n = count($punts);
        for ($i = 0; $i < $n - 1; $i++) {
            $dt = min($punts[$i + 1]->t - $punts[$i]->t, self::MAXIM_SEGONS_PUNT);
            $segons += $dt;
            $fc = $punts[$i]->fc;
            if (! $fc) {
                $senseFc += $dt;

                continue;
            }
            $z = $this->zona($fc, $fcMaxima);
            if ($z) {
                $zones[$z] += $dt / 60;
            }
        }

        return [$zones, $segons, $senseFc];
    }

    /** La pulsació mitjana més alta en una finestra de 30 s: el màxim sense les punxes d'un sol punt. */
    private function sostinguda(array $punts): int
    {
        $millor = 0.0;
        $inici = 0;
        $suma = 0;
        $n = 0;
        foreach ($punts as $p) {
            if (! $p->fc) {
                continue;
            }
            $suma += $p->fc;
            $n++;
            while ($punts[$inici]->t < $p->t - self::FINESTRA_SOSTINGUDA) {
                if ($punts[$inici]->fc) {
                    $suma -= $punts[$inici]->fc;
                    $n--;
                }
                $inici++;
            }
            if ($n >= 5) {
                $millor = max($millor, $suma / $n);
            }
        }

        return (int) floor($millor);
    }

    /**
     * Minuts a les zones 1…5 de Garmin (`hrTimeInZone_*`, en ms), si la sortida en porta.
     *
     * @return array<int, float>|null
     */
    private function zonesDeGarmin(array $originals): ?array
    {
        $zones = [];
        for ($z = 1; $z <= 5; $z++) {
            $zones[$z] = ($originals["hrTimeInZone_{$z}"] ?? 0) / 60000;
        }

        return array_sum($zones) > 0 ? $zones : null;
    }
}
