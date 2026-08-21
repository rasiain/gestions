<?php

namespace App\Services;

use App\Models\MovimentCompteCorrent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Compara el que s'ha pagat de cada pòlissa amb el que se'n va pagar l'any
 * anterior.
 *
 * A diferència de les taxes, aquí no cal declarar cap total a mà: el de l'any
 * passat ja és a les dades. El que sí que cal és comparar el mateix: en un any
 * començat, vuit mesos de 2026 no es poden comparar amb dotze de 2025, i per
 * això la referència és sempre l'any anterior RETALLAT AL MATEIX DIA.
 *
 * Dues dades més que el total anual no diu:
 *   - la PERIODICITAT (mensual, semestral, anual…), deduïda dels càrrecs dels
 *     últims dotze mesos;
 *   - la PRIMA actual i la seva variació respecte de fa un any, que és el que
 *     avisa d'una pujada molt abans que el total de l'any;
 *   - la PREVISIÓ de tancament i el PROPER CÀRREC, que surten de les dues
 *     anteriors: què falta per pagar de l'any i quan hauria d'arribar.
 *
 * Els imports positius (indemnitzacions, retorns de prima) no es resten mai del
 * pagat: es compten a part.
 */
class AssegurancesEstatService
{
    /**
     * Nombre de càrrecs l'any → periodicitat. Els marges absorbeixen el
     * desplaçament del càrrec entre el desembre i el gener.
     *
     * @var array<string, array{0: string, 1: int}>  etiqueta i càrrecs/any canònics
     */
    private const PERIODICITATS = [
        '1'     => ['anual', 1],
        '2'     => ['semestral', 2],
        '3'     => ['quadrimestral', 3],
        '4-5'   => ['trimestral', 4],
        '6-7'   => ['bimestral', 6],
        '11-13' => ['mensual', 12],
    ];

    /**
     * Estat de cada pòlissa (clau `grup||tipus`) per a l'any indicat.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments  moviments d'assegurança, tots els anys
     * @return array<string, array<string, mixed>>
     */
    public function estats(Collection $moviments, int $any, CarbonInterface $referencia): array
    {
        $referenciaAnterior = $referencia->copy()->subYear();

        $estats = [];
        foreach ($moviments->groupBy(fn (MovimentCompteCorrent $m) => self::clau(
            (string) $m->getAttribute('grup_asseguranca'),
            (string) $m->getAttribute('tipus_asseguranca')
        )) as $clau => $movs) {
            $estats[$clau] = $this->estat($movs, $any, $referencia, $referenciaAnterior);
        }

        return $estats;
    }

    public static function clau(string $grup, string $tipus): string
    {
        return $grup . '||' . $tipus;
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $movs
     * @return array<string, mixed>
     */
    private function estat(Collection $movs, int $any, CarbonInterface $referencia, CarbonInterface $referenciaAnterior): array
    {
        // Els imports positius són indemnitzacions o retorns de prima: no són
        // pagaments i no poden abaratir l'any.
        $carrecs = $movs->filter(fn (MovimentCompteCorrent $m) => (float) $m->import < 0)
            ->sortBy(fn (MovimentCompteCorrent $m) => $m->data_moviment->timestamp)
            ->values();

        $pagat          = $this->suma($carrecs, $any);
        $anteriorTotal  = $this->suma($carrecs, $any - 1);
        $anteriorAData  = $this->suma($carrecs, $any - 1, $referenciaAnterior);
        $variacio       = round($pagat - $anteriorAData, 2);

        // La prima és el càrrec més gran de la finestra, no l'últim: en una
        // categoria que barreja el rebut anual amb comissions de 30 €, l'últim
        // càrrec compararia el rebut d'enguany amb una comissió de l'any passat.
        $ultimAny  = $this->finestra($carrecs, $referencia);
        $penultim  = $this->finestra($carrecs, $referenciaAnterior);
        $rebut     = $this->major($ultimAny);
        $rebutAnt  = $this->major($penultim);
        $prima     = $rebut !== null ? round(abs((float) $rebut->import), 2) : null;
        $primaAnt  = $rebutAnt !== null ? round(abs((float) $rebutAnt->import), 2) : null;

        [$periodicitat, $carrecsAny] = $this->periodicitat($ultimAny);

        $pagaments = $this->compta($carrecs, $any);

        // Mentre l'any corre es pot dir què hi falta; un any tancat ja no espera
        // res i la previsió no vol dir res.
        $anyObert = $referencia->format('m-d') !== '12-31';
        $pendents = $anyObert && $carrecsAny !== null ? max(0, $carrecsAny - $pagaments) : null;
        $ultim    = $carrecs->last(fn (MovimentCompteCorrent $m) => $m->data_moviment->lte($referencia));

        return [
            'pagat'             => $pagat,
            'pagaments'         => $pagaments,
            // Els càrrecs que falten es compten a la prima d'ara: és el que
            // costaria l'any si no canviés res més.
            'carrecs_pendents'  => $pendents,
            'previsio'          => $pendents !== null && $prima !== null
                ? round($pagat + $pendents * $prima, 2)
                : null,
            'proper_carrec'     => $pendents > 0 && $carrecsAny !== null && $ultim !== null
                ? $ultim->data_moviment->copy()->addMonths(intdiv(12, $carrecsAny))->toDateString()
                : null,
            // Any anterior retallat al mateix dia: l'única comparació honesta
            // mentre l'any en curs no ha acabat.
            'anterior_a_data'   => $anteriorAData,
            'anterior_total'    => $anteriorTotal,
            'any_incomplet'     => $anteriorAData < $anteriorTotal,
            'variacio'          => $variacio,
            'variacio_pct'      => $anteriorAData > 0 ? round($variacio / $anteriorAData * 100, 1) : null,
            'periodicitat'      => $periodicitat,
            'carrecs_any'       => $carrecsAny,
            'prima'             => $prima,
            'data_prima'        => $rebut?->data_moviment->toDateString(),
            'prima_anterior'    => $primaAnt,
            'prima_variacio_pct' => $prima !== null && $primaAnt > 0
                ? round(($prima - $primaAnt) / $primaAnt * 100, 1)
                : null,
            // Els retorns es mostren, mai no es resten
            'retornat'          => round((float) $movs
                ->filter(fn (MovimentCompteCorrent $m) => (float) $m->import > 0
                    && (int) $m->data_moviment->format('Y') === $any)
                ->sum('import'), 2),
            // Una pòlissa d'un immoble de lloguer que no passa per les despeses
            // és una deducció d'IRPF que s'escapa.
            'sense_classificar' => $carrecs
                ->filter(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y') === $any
                    && $m->despesa === null)
                ->count(),
        ];
    }

    /**
     * Càrrecs de l'any, en positiu, opcionalment fins a una data.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $carrecs
     */
    private function suma(Collection $carrecs, int $any, ?CarbonInterface $fins = null): float
    {
        return round(abs((float) $this->delAny($carrecs, $any, $fins)->sum('import')), 2);
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $carrecs
     */
    private function compta(Collection $carrecs, int $any, ?CarbonInterface $fins = null): int
    {
        return $this->delAny($carrecs, $any, $fins)->count();
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $carrecs
     * @return Collection<int, MovimentCompteCorrent>
     */
    private function delAny(Collection $carrecs, int $any, ?CarbonInterface $fins = null): Collection
    {
        return $carrecs->filter(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y') === $any
            && ($fins === null || $m->data_moviment->lte($fins)));
    }

    /**
     * Càrrecs dels dotze mesos anteriors a la data indicada.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $carrecs
     * @return Collection<int, MovimentCompteCorrent>
     */
    private function finestra(Collection $carrecs, CarbonInterface $fins): Collection
    {
        $desde = $fins->copy()->subYear();

        return $carrecs->filter(fn (MovimentCompteCorrent $m) => $m->data_moviment->gt($desde)
            && $m->data_moviment->lte($fins));
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $carrecs
     */
    private function major(Collection $carrecs): ?MovimentCompteCorrent
    {
        return $carrecs->sortByDesc(fn (MovimentCompteCorrent $m) => abs((float) $m->import))->first();
    }

    /**
     * Periodicitat deduïda dels càrrecs dels últims dotze mesos. Les pòlisses
     * canvien de forma de pagament i se'n donen de baixa: comptar l'any natural
     * en diria coses que ja no són certes.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $finestra  càrrecs dels últims dotze mesos
     * @return array{0: string, 1: ?int}
     */
    private function periodicitat(Collection $finestra): array
    {
        $n = $finestra->count();

        if ($n === 0) {
            return ['inactiva', null];
        }

        foreach (self::PERIODICITATS as $rang => [$etiqueta, $canonics]) {
            [$min, $max] = array_pad(explode('-', $rang), 2, null);

            if ($n >= (int) $min && $n <= (int) ($max ?? $min)) {
                return [$etiqueta, $canonics];
            }
        }

        return ['irregular', null];
    }
}
