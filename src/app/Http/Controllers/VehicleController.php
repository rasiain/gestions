<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRepostatgeRequest;
use App\Http\Requests\VehicleRequest;
use App\Models\MovimentCompteCorrent;
use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use App\Models\VehicleRepostatge;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VehicleController extends Controller
{
    /** Vehicles de motor: comparteixen matrícula, ITV, assegurança i combustible. */
    private const MOTOR = ['cotxe', 'moto'];

    public function vehiclesMotor()
    {
        return $this->llista(
            self::MOTOR,
            'Vehicles a motor',
            'Els vehicles de motor. El control de despeses i de quilòmetres hi anirà a sobre.',
        );
    }

    public function bicis()
    {
        return $this->llista(
            ['bici'],
            'Bicis',
            'Les bicicletes. També tenen despeses i quilòmetres, però ni matrícula ni combustible.',
        );
    }

    /**
     * @param  array<int, string>  $tipus
     */
    private function llista(array $tipus, string $titol, string $descripcio)
    {
        $vehicles = Vehicle::whereIn('tipus', $tipus)
            ->with([
                'repostatges' => fn ($q) => $q->orderBy('data')->orderBy('id'),
                'despeses'    => fn ($q) => $q->orderByDesc('data')->orderByDesc('id'),
            ])
            ->orderBy('ordre')
            ->orderBy('nom')
            ->get()
            ->map(fn (Vehicle $vehicle) => [
                'id'             => $vehicle->id,
                'nom'            => $vehicle->nom,
                'tipus'          => $vehicle->tipus,
                'marca'          => $vehicle->marca,
                'model'          => $vehicle->model,
                'matricula'      => $vehicle->matricula,
                'any_fabricacio' => $vehicle->any_fabricacio,
                'data_alta'      => $vehicle->data_alta?->toDateString(),
                'data_baixa'     => $vehicle->data_baixa?->toDateString(),
                'notes'          => $vehicle->notes,
                'combustible'    => $vehicle->combustible,
                'ordre'          => $vehicle->ordre,
                'actiu'          => $vehicle->esActiu(),
                'repostatges'    => $this->repostatges($vehicle),
                'despeses'       => $vehicle->despeses->map(fn (VehicleDespesa $d) => [
                    'id'          => $d->id,
                    'data'        => $d->data->toDateString(),
                    'tipus'       => $d->tipus,
                    'tipus_nom'   => VehicleDespesa::TIPUS[$d->tipus] ?? $d->tipus,
                    'import'      => (float) $d->import,
                    'km_totals'   => $d->km_totals,
                    'taller'      => $d->taller,
                    'motiu'       => $d->motiu,
                    'moviment_id' => $d->moviment_id,
                ])->values(),
                'resum'          => $this->resum($vehicle),
                'per_any'        => $this->perAny($vehicle),
            ]);

        return Inertia::render('Vehicles/Index', [
            'vehicles'   => $vehicles,
            'tipus'      => $tipus,
            'combustibles' => Vehicle::COMBUSTIBLES,
            'tipusDespesa' => VehicleDespesa::TIPUS,
            'titol'      => $titol,
            'descripcio' => $descripcio,
            // La matrícula i el combustible no pinten res en una bici
            'esMotor'    => $tipus !== ['bici'],
        ]);
    }

    /**
     * Els repostatges d'un vehicle, del més recent al més antic, amb el que es dedueix
     * de cada un: litres, quilòmetres fets des de l'anterior i consum.
     *
     * El consum només té sentit entre dos plens: si el dipòsit no s'ha omplert, els
     * litres posats no són els gastats.
     *
     * @return array<int, array<string, mixed>>
     */
    private function repostatges(Vehicle $vehicle): array
    {
        $files    = [];
        $anterior = null;

        foreach ($vehicle->repostatges as $repostatge) {
            $litres = $repostatge->litres();
            $km     = $anterior !== null ? $repostatge->km_totals - $anterior->km_totals : null;

            $files[] = [
                'id'          => $repostatge->id,
                'data'        => $repostatge->data->toDateString(),
                'km_totals'   => $repostatge->km_totals,
                'preu_litre'  => (float) $repostatge->preu_litre,
                'cost'        => (float) $repostatge->cost,
                'benzinera'   => $repostatge->benzinera,
                'diposit_ple' => $repostatge->diposit_ple,
                'litres'      => $litres,
                'km'          => $km,
                // Litres per cada cent quilòmetres, entre dos plens
                'consum'      => $km > 0 && $litres !== null && $repostatge->diposit_ple
                    ? round($litres / $km * 100, 2)
                    : null,
                'moviment_id' => $repostatge->moviment_id,
                'notes'       => $repostatge->notes,
            ];

            $anterior = $repostatge;
        }

        return array_reverse($files);
    }

    /**
     * El que diuen tots els repostatges junts.
     *
     * Els litres del primer no compten: van servir per als quilòmetres d'abans, que no
     * sabem. El consum mitjà és, doncs, els litres del segon en endavant sobre els
     * quilòmetres fets entre el primer i el darrer.
     *
     * @return array<string, mixed>|null
     */
    private function resum(Vehicle $vehicle): ?array
    {
        $repostatges = $vehicle->repostatges;

        if ($repostatges->count() < 2) {
            return null;
        }

        $primer = $repostatges->first();
        $darrer = $repostatges->last();
        $km     = $darrer->km_totals - $primer->km_totals;

        $litres = round((float) $repostatges->skip(1)->sum(fn (VehicleRepostatge $r) => $r->litres() ?? 0), 2);
        $cost   = round((float) $repostatges->skip(1)->sum(fn (VehicleRepostatge $r) => (float) $r->cost), 2);

        return [
            'des_de'      => $primer->data->toDateString(),
            'fins_a'      => $darrer->data->toDateString(),
            'repostatges' => $repostatges->count(),
            'km'          => $km,
            'litres'      => $litres,
            'cost'        => $cost,
            'consum'      => $km > 0 ? round($litres / $km * 100, 2) : null,
            // El que costa moure el vehicle cada cent quilòmetres, només de combustible
            'cost_100km'  => $km > 0 ? round($cost / $km * 100, 2) : null,
        ];
    }

    /**
     * El que ha donat cada any: quilòmetres, combustible i despeses.
     *
     * Dues mesures de quilòmetres, perquè serveixen per a coses diferents:
     *
     * - `km` només compta **entre repostatges**. És la que va amb els litres: els litres
     *   de l'any són els que s'han posat entre el primer i el darrer repostatge, i només
     *   dividint pels quilòmetres d'aquest mateix tram surt un consum que vol dir alguna
     *   cosa. Es queda curta —del darrer repostatge a Cap d'Any encara s'hi roda—, i és
     *   el preu de tenir un L/100 km comparable d'un any a l'altre.
     * - `km_estimats` hi afegeix les lectures apuntades a les despeses (taller, ITV). És
     *   la millor estimació del que s'ha rodat de veritat, i per això és la que divideix
     *   el cost. No serveix per al consum: aquests quilòmetres no tenen litres al costat.
     *
     * Totes dues són la darrera lectura de l'any menys la darrera d'abans que comencés.
     * Sense lectura de l'any, o sense cap d'anterior —el primer any—, no se saben.
     *
     * @return array<int, array<string, mixed>>
     */
    private function perAny(Vehicle $vehicle): array
    {
        $delsRepostatges = $vehicle->repostatges
            ->map(fn (VehicleRepostatge $r) => ['data' => $r->data, 'km' => $r->km_totals]);

        $lectures = $this->ordenaLectures($delsRepostatges);

        // Les visites al taller també deixen el comptador apuntat
        $totes = $this->ordenaLectures($delsRepostatges->concat($vehicle->despeses
            ->filter(fn (VehicleDespesa $d) => $d->km_totals !== null)
            ->map(fn (VehicleDespesa $d) => ['data' => $d->data, 'km' => $d->km_totals])));

        $anys = $vehicle->repostatges->map(fn ($r) => (int) $r->data->format('Y'))
            ->concat($vehicle->despeses->map(fn ($d) => (int) $d->data->format('Y')))
            ->unique()
            ->sortDesc()
            ->values();

        $files = [];

        foreach ($anys as $any) {
            $km         = $this->kmDeLany($lectures, $any);
            $kmEstimats = $this->kmDeLany($totes, $any);

            $repostatges = $vehicle->repostatges->filter(fn ($r) => (int) $r->data->format('Y') === $any);
            $despeses    = $vehicle->despeses->filter(fn ($d) => (int) $d->data->format('Y') === $any);

            $litres   = round((float) $repostatges->sum(fn (VehicleRepostatge $r) => $r->litres() ?? 0), 2);
            $carburant = round((float) $repostatges->sum(fn (VehicleRepostatge $r) => (float) $r->cost), 2);
            $altres    = round((float) $despeses->sum(fn (VehicleDespesa $d) => (float) $d->import), 2);

            $files[] = [
                'any'         => $any,
                'km'          => $km,
                'km_estimats' => $kmEstimats,
                'litres'      => $litres,
                'consum'      => $km > 0 && $litres > 0 ? round($litres / $km * 100, 2) : null,
                'carburant'   => $carburant,
                'altres'      => $altres,
                'total'       => round($carburant + $altres, 2),
                // El cost es divideix pels quilòmetres més ben estimats, no pels del consum
                'cost_km'     => ($kmEstimats ?? $km) > 0
                    ? round(($carburant + $altres) / ($kmEstimats ?? $km), 3)
                    : null,
                'repostatges' => $repostatges->count(),
                'despeses'    => $despeses->count(),
                // Què s'ha gastat en cada cosa
                'per_tipus'   => $despeses->groupBy('tipus')
                    ->map(fn ($grup) => round((float) $grup->sum(fn (VehicleDespesa $d) => (float) $d->import), 2)),
            ];
        }

        return $files;
    }

    /**
     * Lectures del comptador de la més antiga a la més nova. Un mateix dia pot tenir-ne
     * dues —dos repostatges, o un repostatge i el taller—, i llavors mana el comptador,
     * que només puja.
     *
     * @param  \Illuminate\Support\Collection<int, array{data: \Carbon\CarbonInterface, km: int}>  $lectures
     * @return \Illuminate\Support\Collection<int, array{data: \Carbon\CarbonInterface, km: int}>
     */
    private function ordenaLectures(\Illuminate\Support\Collection $lectures): \Illuminate\Support\Collection
    {
        return $lectures
            ->sortBy([fn ($a, $b) => $a['data'] <=> $b['data'], fn ($a, $b) => $a['km'] <=> $b['km']])
            ->values();
    }

    /**
     * Els quilòmetres d'un any: la darrera lectura de l'any menys la darrera d'abans que
     * comencés. Null si l'any no en té cap —no se sap fins on va arribar— o si no n'hi ha
     * cap d'anterior amb què comparar.
     *
     * @param  \Illuminate\Support\Collection<int, array{data: \Carbon\CarbonInterface, km: int}>  $lectures
     */
    private function kmDeLany(\Illuminate\Support\Collection $lectures, int $any): ?int
    {
        $fins  = $lectures->last(fn (array $l) => (int) $l['data']->format('Y') === $any);
        $abans = $lectures->last(fn (array $l) => (int) $l['data']->format('Y') < $any);

        return $fins !== null && $abans !== null ? $fins['km'] - $abans['km'] : null;
    }

    // ---- Despeses ----

    public function storeDespesa(\App\Http\Requests\VehicleDespesaRequest $request)
    {
        $despesa = VehicleDespesa::create($request->validated());

        return $this->tornaALaLlista($despesa->vehicle, 'Despesa desada.');
    }

    public function updateDespesa(\App\Http\Requests\VehicleDespesaRequest $request, VehicleDespesa $despesa)
    {
        $despesa->update($request->validated());

        return $this->tornaALaLlista($despesa->vehicle, 'Despesa actualitzada.');
    }

    public function destroyDespesa(VehicleDespesa $despesa)
    {
        $vehicle = $despesa->vehicle;
        $despesa->delete();

        return $this->tornaALaLlista($vehicle, 'Despesa eliminada.');
    }

    // ---- Repostatges ----

    public function storeRepostatge(VehicleRepostatgeRequest $request)
    {
        $repostatge = VehicleRepostatge::create($request->validated());

        return $this->tornaALaLlista($repostatge->vehicle, 'Repostatge desat.');
    }

    public function updateRepostatge(VehicleRepostatgeRequest $request, VehicleRepostatge $repostatge)
    {
        $repostatge->update($request->validated());

        return $this->tornaALaLlista($repostatge->vehicle, 'Repostatge actualitzat.');
    }

    public function destroyRepostatge(VehicleRepostatge $repostatge)
    {
        $vehicle = $repostatge->vehicle;
        $repostatge->delete();

        return $this->tornaALaLlista($vehicle, 'Repostatge eliminat.');
    }

    /**
     * Moviments de combustible d'una data que encara no estan lligats a cap repostatge.
     *
     * Serveix per proposar-los en apuntar-ne un: així el cost no s'escriu dues vegades i,
     * de passada, se sap de quin vehicle és cada repostatge de `MOTOR > GASOLINA`.
     */
    public function movimentsCombustible(\Illuminate\Http\Request $request)
    {
        $data = $request->input('data');

        if (! is_string($data) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return response()->json([]);
        }

        $categories = \App\Models\Categoria::all()
            ->filter(fn ($c) => str_contains(mb_strtoupper($c->nom), 'GASOLINA')
                || str_contains(mb_strtoupper($c->nom), 'CARBURANT'))
            ->pluck('id');

        // En editar-ne un, el seu moviment no compta com a ocupat: és seu
        $usats = VehicleRepostatge::whereNotNull('moviment_id')
            ->when($request->integer('repostatge'), fn ($q, $id) => $q->whereKeyNot($id))
            ->pluck('moviment_id');

        return response()->json(
            MovimentCompteCorrent::whereIn('categoria_id', $categories)
                ->whereNotIn('id', $usats)
                // Un dia amunt o avall: la data de l'extracte no sempre és la del sortidor
                ->whereDate('data_moviment', '>=', date('Y-m-d', strtotime($data . ' -2 days')))
                ->whereDate('data_moviment', '<=', date('Y-m-d', strtotime($data . ' +2 days')))
                ->with('compteCorrent:id,nom,compte_corrent')
                ->orderBy('data_moviment')
                ->get()
                ->map(fn (MovimentCompteCorrent $m) => [
                    'id'     => $m->id,
                    'data'   => $m->data_moviment->toDateString(),
                    'import' => abs((float) $m->import),
                    'compte' => $m->compteCorrent?->nom,
                ])
        );
    }

    public function store(VehicleRequest $request)
    {
        $vehicle = Vehicle::create($request->validated());

        return $this->tornaALaLlista($vehicle, 'Vehicle creat correctament.');
    }

    public function update(VehicleRequest $request, Vehicle $vehicle)
    {
        $vehicle->update($request->validated());

        return $this->tornaALaLlista($vehicle, 'Vehicle actualitzat correctament.');
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();

        return $this->tornaALaLlista($vehicle, 'Vehicle eliminat correctament.');
    }

    /** Cada tipus té la seva llista: s'ha de tornar a la que toca. */
    private function tornaALaLlista(Vehicle $vehicle, string $missatge): RedirectResponse
    {
        return redirect()
            ->route($vehicle->tipus === 'bici' ? 'bicis.index' : 'vehicles-motor.index')
            ->with('success', $missatge);
    }
}
