<?php

namespace App\Http\Controllers;

use App\Services\PatrimoniMobiliariService;
use Inertia\Inertia;
use Inertia\Response;

class TotalsMobiliarisController extends Controller
{
    public function __construct(
        private PatrimoniMobiliariService $patrimoni
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
        ]);
    }
}
