<?php

namespace App\Services;

use App\Models\CapitalSocialContracte;
use App\Models\CapitalSocialValor;
use App\Models\CompteCorrent;
use App\Models\FonsInversio;
use App\Models\PlaPensions;
use App\Models\RendaFixaContracte;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tot el patrimoni mobiliari com una sola llista de posicions.
 *
 * Cada pantalla d'inversions ja diu què val el que hi té, però ho diu pel seu compte:
 * els comptes corrents pel saldo, els fons i els plans per participacions × cotització,
 * la renda fixa pel valor patrimonial i el capital social per títols × nominal. Aquí es posen
 * totes en la mateixa forma —un nom,
 * un valor i com es reparteix entre els titulars— perquè es puguin sumar.
 *
 * El repartiment és **a parts iguals** entre els titulars del compte, que és el que fan
 * les tres pantalles, l'últim titular s'endú el residu perquè la suma doni exactament el
 * valor de la posició. L'excepció són els comptes d'un lloguer l'immoble del qual té
 * **quotes de titularitat** desades (`g_propietaris_immobles`): allà manen les quotes, i
 * com que tenen dates, poden canviar al llarg de la sèrie. Per això cada posició porta els
 * seus **trams de repartiment** i la pantalla tria el que toca a cada mes.
 *
 * Cada posició porta, a més del que val avui, **què valia a final de cada mes** des que hi
 * ha dades. No cal desar-ho enlloc: el saldo d'un compte a una data és el `saldo_posterior`
 * del darrer moviment fins aquell dia, i el d'un fons, les participacions aportades fins
 * aleshores per la cotització que hi havia. La sèrie s'envia sencera i la pantalla en tria
 * el tall i el rang, com fa amb els titulars.
 */
class PatrimoniMobiliariService
{
    /** Les fonts que se sumen, en l'ordre en què es llegeixen. */
    public const FONTS = ['comptes', 'fons', 'pensions', 'renda_fixa', 'capital_social'];

    /**
     * Una fila per posició: un compte, un contracte de fons, de pla o de renda fixa.
     *
     * @param  array<int, string>|null  $mesos  els talls de la sèrie; si no se'n donen, tots
     * @return array<int, array<string, mixed>>
     */
    public function posicions(?array $mesos = null): array
    {
        $mesos ??= $this->mesos();

        return [
            ...$this->comptes($mesos),
            ...$this->fons($mesos),
            ...$this->pensions($mesos),
            ...$this->rendaFixa($mesos),
            ...$this->capitalSocial($mesos),
        ];
    }

    /**
     * Els mesos que té la sèrie: del primer amb dades fins al d'ara, com a «AAAA-MM».
     *
     * El tall d'un mes és sempre el seu últim dia. El mes en curs encara no s'ha acabat, però
     * hi és: el seu valor és el d'avui, que és el que es vol veure a la punta de la sèrie.
     *
     * @return array<int, string>
     */
    public function mesos(): array
    {
        $primer = collect([
            DB::table('g_moviments_comptes_corrents')->min('data_moviment'),
            DB::table('g_fi_aportacions')->min('data'),
            DB::table('g_pp_aportacions')->min('data'),
            DB::table('g_rf_contractes')->min('data_compra'),
            DB::table('g_cs_valors')->min('data'),
        ])->filter()->min();

        $fins  = Carbon::now()->startOfMonth();
        $mes   = $primer === null ? $fins->copy() : Carbon::parse($primer)->startOfMonth();
        $mesos = [];

        for (; $mes <= $fins; $mes->addMonth()) {
            $mesos[] = $mes->format('Y-m');
        }

        return $mesos;
    }

    /**
     * Els trams de repartiment d'un compte: qui hi té quina part, i des de quan.
     *
     * Sense quotes n'hi ha un de sol, a parts iguals i sense data. Amb quotes n'hi ha un
     * per cada canvi de titularitat, i el pes de cadascú és la seva quota d'aquell tram.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Persona>  $titulars
     * @return array<int, array<string, mixed>>
     */
    private function trams(?int $compteId, Collection $titulars): array
    {
        $unic = [[
            'des_de' => null,
            'parts'  => $titulars->map(fn ($t) => [
                'titular_id' => $t->id,
                'nom'        => trim($t->nom . ' ' . $t->cognoms),
                'pes'        => 1.0,
            ])->values()->all(),
        ]];

        if ($compteId === null) {
            return $unic;
        }

        $quotes = $this->quotesDelCompte($compteId);

        return $quotes === [] ? $unic : $quotes;
    }

    /**
     * Les quotes de l'immoble del lloguer d'aquest compte, en trams.
     *
     * Un compte que reculli lloguers d'immobles diferents no té una resposta única: en
     * aquest cas es deixa estar i el repartiment torna a ser a parts iguals.
     *
     * @return array<int, array<string, mixed>>
     */
    private function quotesDelCompte(int $compteId): array
    {
        $immobles = \App\Models\Lloguer::where('compte_corrent_id', $compteId)
            ->with('immoble.propietaris')
            ->get()
            ->map(fn ($ll) => $ll->immoble)
            ->filter()
            ->unique('id');

        if ($immobles->count() !== 1) {
            return [];
        }

        $propietaris = $immobles->first()->propietaris
            ->filter(fn ($p) => $p->pivot->quota !== null);

        if ($propietaris->isEmpty()) {
            return [];
        }

        // Cada data d'alta o de baixa obre un tram nou
        $dates = $propietaris
            ->flatMap(fn ($p) => [$this->mesDe($p->pivot->data_inici), $this->mesSeguent($p->pivot->data_fi)])
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $dates->map(function (string $mes) use ($propietaris) {
            $vigents = $propietaris->filter(fn ($p) =>
                $this->mesDe($p->pivot->data_inici) <= $mes
                && ($p->pivot->data_fi === null || $this->mesDe($p->pivot->data_fi) >= $mes)
            );

            return [
                'des_de' => $mes,
                'parts'  => $vigents->map(fn ($p) => [
                    'titular_id' => $p->id,
                    'nom'        => trim($p->nom . ' ' . $p->cognoms),
                    'pes'        => (float) $p->pivot->quota,
                ])->values()->all(),
            ];
        })->filter(fn (array $tram) => $tram['parts'] !== [])->values()->all();
    }

    private function mesDe(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        return $data instanceof \DateTimeInterface ? $data->format('Y-m') : substr((string) $data, 0, 7);
    }

    /** El mes que ve després d'una baixa: quan la titularitat ja no hi és. */
    private function mesSeguent(mixed $data): ?string
    {
        $mes = $this->mesDe($data);

        return $mes === null ? null : Carbon::parse($mes . '-01')->addMonth()->format('Y-m');
    }

    /**
     * Els titulars que tenen alguna cosa, ordenats per nom.
     *
     * No són totes les persones: triar algú que no té cap posició no diria res.
     *
     * @param  array<int, array<string, mixed>>  $posicions
     * @return array<int, array<string, mixed>>
     */
    public function titulars(array $posicions): array
    {
        return collect($posicions)
            ->flatMap(fn (array $p) => $p['parts'])
            ->unique(fn (array $part) => $part['titular_id'] ?? 'sense')
            ->map(fn (array $part) => ['id' => $part['titular_id'], 'nom' => $part['nom']])
            ->sortBy('nom', SORT_LOCALE_STRING)
            ->values()
            ->all();
    }

    /**
     * Els comptes del dia a dia, pel saldo d'avui.
     *
     * Els comptes d'inversió no hi entren encara que siguin comptes: el seu valor són els
     * contractes que hi pengen, i comptar-los dues vegades el doblaria.
     *
     * @param  array<int, string>  $mesos
     * @return array<int, array<string, mixed>>
     */
    private function comptes(array $mesos): array
    {
        $lloguers = \App\Models\Lloguer::pluck('nom', 'compte_corrent_id');
        $saldos   = $this->saldosPerMes($mesos);

        return CompteCorrent::where('tipus', 'corrent')
            ->with(['titulars', 'entitatRelacio'])
            ->get()
            ->map(fn (CompteCorrent $c) => $this->posicio(
                font: 'comptes',
                id: $c->id,
                nom: $c->nom ?? $c->compte_corrent,
                detall: $c->entitat,
                etiqueta: $lloguers->get($c->id) !== null ? 'lloguer' : null,
                valor: (float) $c->saldo_actual,
                titulars: $c->titulars,
                serie: $saldos[$c->id] ?? $this->arrossega([], $mesos),
                compteId: $c->id,
            ))
            ->all();
    }

    /**
     * El saldo de cada compte a final de cada mes.
     *
     * És el `saldo_posterior` del darrer moviment del mes, arrossegat als mesos sense cap
     * moviment. Es llegeix amb un cursor i quatre columnes perquè hi ha desenes de milers de
     * moviments i només en calen els darrers de cada mes.
     *
     * @param  array<int, string>  $mesos
     * @return array<int, array<string, float>>
     */
    private function saldosPerMes(array $mesos): array
    {
        $perCompte = [];

        DB::table('g_moviments_comptes_corrents')
            ->select('compte_corrent_id', 'data_moviment', 'id', 'saldo_posterior')
            ->orderBy('compte_corrent_id')
            ->orderBy('data_moviment')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $m) use (&$perCompte): void {
                $perCompte[$m->compte_corrent_id][substr((string) $m->data_moviment, 0, 7)] = (float) $m->saldo_posterior;
            });

        return array_map(fn (array $perMes) => $this->arrossega($perMes, $mesos), $perCompte);
    }

    /**
     * Els fons: participacions del contracte per la darrera cotització del fons.
     *
     * @param  array<int, string>  $mesos
     * @return array<int, array<string, mixed>>
     */
    private function fons(array $mesos): array
    {
        return FonsInversio::with(['valors', 'contractes.aportacions', 'contractes.compteCorrent.titulars'])
            ->get()
            ->flatMap(function (FonsInversio $f) use ($mesos) {
                $valorPart    = (float) ($f->valors->sortByDesc('data')->first()?->valor_participacio ?? 0);
                $cotitzacions = $this->cotitzacions($f->valors, $mesos);

                return $f->contractes->map(fn ($c) => $this->posicio(
                    font: 'fons',
                    id: $c->id,
                    nom: $f->nom,
                    detall: $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent,
                    etiqueta: null,
                    valor: round($c->aportacions->sum(fn ($a) => (float) $a->participacions) * $valorPart, 2),
                    titulars: $c->compteCorrent?->titulars ?? collect(),
                    serie: $this->serieParticipacions($c->aportacions, $cotitzacions, $mesos),
                    compteId: $c->compte_corrent_id,
                ));
            })
            ->all();
    }

    /**
     * Els plans de pensions es valoren igual que els fons.
     *
     * @param  array<int, string>  $mesos
     * @return array<int, array<string, mixed>>
     */
    private function pensions(array $mesos): array
    {
        return PlaPensions::with(['valors', 'contractes.aportacions', 'contractes.compteCorrent.titulars'])
            ->get()
            ->flatMap(function (PlaPensions $p) use ($mesos) {
                $valorPart    = (float) ($p->valors->sortByDesc('data')->first()?->valor_participacio ?? 0);
                $cotitzacions = $this->cotitzacions($p->valors, $mesos);

                return $p->contractes->map(fn ($c) => $this->posicio(
                    font: 'pensions',
                    id: $c->id,
                    nom: $p->nom,
                    detall: $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent,
                    etiqueta: null,
                    valor: round($c->aportacions->sum(fn ($a) => (float) $a->participacions) * $valorPart, 2),
                    titulars: $c->compteCorrent?->titulars ?? collect(),
                    serie: $this->serieParticipacions($c->aportacions, $cotitzacions, $mesos),
                    compteId: $c->compte_corrent_id,
                ));
            })
            ->all();
    }

    /**
     * La renda fixa, pel darrer valor patrimonial declarat (i si no n'hi ha, pel nominal).
     *
     * @param  array<int, string>  $mesos
     * @return array<int, array<string, mixed>>
     */
    private function rendaFixa(array $mesos): array
    {
        return RendaFixaContracte::with(['titol', 'valors', 'compteCorrent.titulars'])
            ->get()
            ->map(fn (RendaFixaContracte $c) => $this->posicio(
                font: 'renda_fixa',
                id: $c->id,
                nom: $c->titol?->nom ?? 'Sense títol',
                detall: $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent,
                etiqueta: null,
                valor: $c->valorAData(),
                titulars: $c->compteCorrent?->titulars ?? collect(),
                serie: $this->serieRendaFixa($c, $mesos),
                compteId: $c->compte_corrent_id,
            ))
            ->all();
    }

    /**
     * El capital social: títols per nominal unitari, o el saldo quan no ve desglossat.
     *
     * El nom de la posició és el del compte, que és on hi ha l'entitat i el número de
     * contracte; les aportacions que no tenen compte —una cooperativa de consum— porten el
     * nom de qui les emet i els seus propis titulars.
     *
     * @param  array<int, string>  $mesos
     * @return array<int, array<string, mixed>>
     */
    private function capitalSocial(array $mesos): array
    {
        return CapitalSocialContracte::with([
                'valors',
                'compteCorrent.titulars',
                'compteCorrent.entitatRelacio',
                'titularsPropis',
            ])
            ->get()
            ->map(fn (CapitalSocialContracte $c) => $this->posicio(
                font: 'capital_social',
                id: $c->id,
                nom: $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent ?? $c->emissor ?? 'Capital social',
                detall: $c->emissor_visible,
                etiqueta: null,
                valor: $c->valorAData(),
                titulars: $c->titulars(),
                serie: $this->serieCapitalSocial($c, $mesos),
                compteId: $c->compte_corrent_id,
            ))
            ->all();
    }

    /**
     * Què valia el capital social a final de cada mes.
     *
     * Abans del primer valor declarat no val res: aquí no hi ha cap nominal de contracte
     * que serveixi de mínim, com sí que en té la renda fixa.
     *
     * @param  array<int, string>  $mesos
     * @return array<string, float>
     */
    private function serieCapitalSocial(CapitalSocialContracte $c, array $mesos): array
    {
        $perMes = [];

        foreach ($c->valors->sortBy(fn ($v) => $v->data->timestamp) as $valor) {
            $perMes[$valor->data->format('Y-m')] = $valor->total;
        }

        return $this->arrossega($perMes, $mesos);
    }

    /**
     * Què valia un contracte de fons o de pla a final de cada mes.
     *
     * Les participacions són les aportades fins aleshores —una aportació de fa dos anys no
     * hi era fa tres— i la cotització, la que hi havia aquell mes.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $aportacions
     * @param  array<string, float>  $cotitzacions
     * @param  array<int, string>  $mesos
     * @return array<string, float>
     */
    private function serieParticipacions(Collection $aportacions, array $cotitzacions, array $mesos): array
    {
        $acumulat = 0.0;
        $perMes   = [];

        foreach ($aportacions->sortBy(fn ($a) => $a->data->timestamp) as $aportacio) {
            $acumulat += (float) $aportacio->participacions;
            $perMes[$aportacio->data->format('Y-m')] = $acumulat;
        }

        $participacions = $this->arrossega($perMes, $mesos);
        $serie          = [];

        foreach ($mesos as $mes) {
            $serie[$mes] = round($participacions[$mes] * $cotitzacions[$mes], 2);
        }

        return $serie;
    }

    /**
     * La cotització vigent a final de cada mes.
     *
     * Abans de la primera cotització coneguda s'hi val aquesta mateixa: si el contracte és més
     * vell que la sèrie de valors, valorar-lo a zero diria que no hi havia res, i sí que hi era.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $valors
     * @param  array<int, string>  $mesos
     * @return array<string, float>
     */
    private function cotitzacions(Collection $valors, array $mesos): array
    {
        $ordenats = $valors->sortBy(fn ($v) => $v->data->timestamp);
        $perMes   = [];

        foreach ($ordenats as $valor) {
            $perMes[$valor->data->format('Y-m')] = (float) $valor->valor_participacio;
        }

        return $this->arrossega($perMes, $mesos, (float) ($ordenats->first()?->valor_participacio ?? 0));
    }

    /**
     * Què valia un contracte de renda fixa a final de cada mes.
     *
     * Mentre no hi ha cap valor declarat val el nominal, com a la seva pantalla, i abans de
     * comprar-lo no val res: la sèrie no ha d'ensenyar patrimoni que encara no existia.
     *
     * @param  array<int, string>  $mesos
     * @return array<string, float>
     */
    private function serieRendaFixa(RendaFixaContracte $c, array $mesos): array
    {
        $perMes = [];

        foreach ($c->valors->sortBy(fn ($v) => $v->data->timestamp) as $valor) {
            $perMes[$valor->data->format('Y-m')] = (float) $valor->valor_patrimonial;
        }

        $serie  = $this->arrossega($perMes, $mesos, (float) $c->nominal);
        $compra = $c->data_compra?->format('Y-m');

        if ($compra !== null) {
            foreach ($mesos as $mes) {
                if ($mes < $compra) {
                    $serie[$mes] = 0.0;
                }
            }
        }

        return $serie;
    }

    /**
     * El valor de cada mes arrossegant el darrer conegut.
     *
     * Un compte sense moviments un mes val el que valia el mes abans; abans del primer, el
     * que digui `$abans` (cap saldo, o la primera cotització coneguda).
     *
     * @param  array<string, float>  $perMes
     * @param  array<int, string>  $mesos
     * @return array<string, float>
     */
    private function arrossega(array $perMes, array $mesos, float $abans = 0.0): array
    {
        $serie = [];
        $ultim = $abans;

        foreach ($mesos as $mes) {
            $ultim       = $perMes[$mes] ?? $ultim;
            $serie[$mes] = $ultim;
        }

        return $serie;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Persona>  $titulars
     * @param  array<string, float>  $serie
     * @return array<string, mixed>
     */
    private function posicio(
        string $font,
        int $id,
        string $nom,
        ?string $detall,
        ?string $etiqueta,
        float $valor,
        Collection $titulars,
        array $serie = [],
        ?int $compteId = null,
    ): array {
        $trams = $this->trams($compteId, $titulars);

        return [
            // La clau identifica la posició a la tria de la pantalla
            'clau'     => $font . '-' . $id,
            // Qui se'n reparteix el valor, i des de quan: les quotes poden canviar
            'trams'    => $trams,
            'font'     => $font,
            'nom'      => $nom,
            'detall'   => $detall,
            'etiqueta' => $etiqueta,
            'valor'    => round($valor, 2),
            'parts'    => $this->reparteix($valor, $this->tramVigent($trams)),
            // Què valia a final de cada mes; la pantalla en tria el tall
            'serie'    => $serie,
        ];
    }

    /** El tram que val avui: l'últim que ja ha començat. */
    private function tramVigent(array $trams): array
    {
        $ara = Carbon::now()->format('Y-m');

        foreach (array_reverse($trams) as $tram) {
            if ($tram['des_de'] === null || $tram['des_de'] <= $ara) {
                return $tram['parts'];
            }
        }

        return $trams[0]['parts'] ?? [];
    }

    /**
     * El valor repartit segons el pes de cadascú, amb el residu per a l'últim.
     *
     * Els pesos són tots 1 quan va a parts iguals i les quotes de l'immoble quan n'hi ha.
     * Una posició sense titular no es pot repartir, però tampoc s'ha de perdre: va a parar
     * a una fila «Sense titular», com a la pantalla de comptes corrents.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function reparteix(float $valor, array $parts): array
    {
        $files = $parts === [] ? [['titular_id' => null, 'nom' => 'Sense titular', 'pes' => 1.0]] : $parts;
        $total = array_sum(array_column($files, 'pes')) ?: 1.0;

        $repartit = 0.0;
        $ultim    = count($files) - 1;

        foreach ($files as $i => $fila) {
            $part = $i === $ultim
                ? round($valor - $repartit, 2)
                : round($valor * $fila['pes'] / $total, 2);
            $repartit += $part;

            $files[$i]['part'] = $part;
            unset($files[$i]['pes']);
        }

        return $files;
    }
}
