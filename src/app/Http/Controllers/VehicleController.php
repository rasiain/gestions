<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRequest;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VehicleController extends Controller
{
    /** Vehicles de motor: comparteixen matrícula, ITV, assegurança i combustible. */
    private const MOTOR = ['cotxe', 'moto'];

    public function cotxes()
    {
        return $this->llista(
            self::MOTOR,
            'Cotxes i motos',
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
                'ordre'          => $vehicle->ordre,
                'actiu'          => $vehicle->esActiu(),
            ]);

        return Inertia::render('Vehicles/Index', [
            'vehicles'   => $vehicles,
            'tipus'      => $tipus,
            'titol'      => $titol,
            'descripcio' => $descripcio,
            // La matrícula i el combustible no pinten res en una bici
            'esMotor'    => $tipus !== ['bici'],
        ]);
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
            ->route($vehicle->tipus === 'bici' ? 'bicis.index' : 'cotxes.index')
            ->with('success', $missatge);
    }
}
