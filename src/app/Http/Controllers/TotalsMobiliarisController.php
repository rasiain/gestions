<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatrimoniNotaRequest;
use App\Models\PatrimoniNota;
use App\Services\FluxosPatrimoniService;
use App\Services\NotesPatrimoniService;
use App\Services\PatrimoniMobiliariService;
use Inertia\Inertia;
use Inertia\Response;

class TotalsMobiliarisController extends Controller
{
    public function __construct(
        private PatrimoniMobiliariService $patrimoni,
        private NotesPatrimoniService $notes,
        private FluxosPatrimoniService $fluxos,
    ) {}

    /**
     * Els totals mobiliaris per titular.
     *
     * S'envien totes les posicions amb el seu repartiment ja fet i la sèrie del que valien
     * a final de cada mes, i la tria —quins titulars, quines posicions i quin tall— es
     * resol a la pantalla. Són poques dades i així cada casella que es marca respon a
     * l'instant, sense tornar al servidor.
     */
    public function index(): Response
    {
        $mesos     = $this->patrimoni->mesos();
        $posicions = $this->patrimoni->posicions($mesos);

        return Inertia::render('Inversions/TotalsMobiliaris', [
            'posicions' => $posicions,
            'titulars'  => $this->patrimoni->titulars($posicions),
            'mesos'     => $mesos,
            // Els salts que tenen nom: els moviments grossos i el que s'hi ha escrit
            'notes'     => $this->notes->notes(),
            'llindar'   => [
                'minim'       => NotesPatrimoniService::MINIM,
                'per_defecte' => NotesPatrimoniService::PER_DEFECTE,
            ],
            // Quants diners entren i en surten, i quins traspassos podrien ser interns
            ...$this->fluxos->calcula(),
        ]);
    }

    /**
     * Desa el que s'escriu sobre una nota.
     *
     * Sobre un moviment n'hi ha una de sola: tornar-hi la corregeix en comptes de fer-ne
     * una segona que digui una altra cosa del mateix salt.
     */
    public function storeNota(PatrimoniNotaRequest $request)
    {
        $dades = $request->validated();

        $nota = PatrimoniNota::updateOrCreate(
            ['moviment_id' => $dades['moviment_id'] ?? null],
            collect($dades)->except(['titulars', 'moviment_id'])->all(),
        );

        if (($dades['moviment_id'] ?? null) === null) {
            $nota->titulars()->sync($dades['titulars'] ?? []);
        }

        return back();
    }

    public function updateNota(PatrimoniNotaRequest $request, PatrimoniNota $nota)
    {
        $dades = $request->validated();
        $nota->update(collect($dades)->except(['titulars', 'moviment_id'])->all());

        if ($nota->moviment_id === null) {
            $nota->titulars()->sync($dades['titulars'] ?? []);
        }

        return back();
    }

    public function destroyNota(PatrimoniNota $nota)
    {
        $nota->delete();

        return back();
    }
}
