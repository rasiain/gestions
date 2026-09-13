<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImmobleRequest;
use App\Models\Immoble;
use App\Models\Persona;
use Inertia\Inertia;

class ImmobleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $immobles = Immoble::with(['propietaris', 'administrador'])
            ->orderBy('adreca')
            ->get()
            ->map(function ($immoble) {
                return [
                    'id' => $immoble->id,
                    'referencia_cadastral' => $immoble->referencia_cadastral,
                    'adreca' => $immoble->adreca,
                    'poblacio' => $immoble->poblacio,
                    'superficie_construida' => $immoble->superficie_construida,
                    'superficie_parcela' => $immoble->superficie_parcela,
                    'us' => $immoble->us,
                    'valor_sol' => $immoble->valor_sol,
                    'valor_construccio' => $immoble->valor_construccio,
                    'valor_cadastral' => $immoble->valor_cadastral,
                    'valor_adquisicio' => $immoble->valor_adquisicio,
                    'referencia_administracio' => $immoble->referencia_administracio,
                    'administrador_id' => $immoble->administrador_id,
                    'administrador' => $immoble->administrador,
                    'propietaris' => $immoble->propietaris,
                    'created_at' => $immoble->created_at,
                    'updated_at' => $immoble->updated_at,
                ];
            });

        $persones = Persona::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        $proveidors = \App\Models\Proveidor::orderBy('nom_rao_social')
            ->get();

        return Inertia::render('Immobles/Index', [
            'immobles' => $immobles,
            'persones' => $persones,
            'proveidors' => $proveidors,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ImmobleRequest $request)
    {
        $validated = $request->validated();

        // Extract propietari data
        $propietaris = $this->propietarisDelFormulari($validated);

        unset(
            $validated['propietari_ids'],
            $validated['propietari_data_inici'],
            $validated['propietari_data_fi'],
            $validated['propietari_quota'],
        );

        $immoble = Immoble::create($validated);

        $this->desaPropietaris($immoble, $propietaris);

        return redirect()->route('immobles.index')
            ->with('success', 'Immoble creat correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ImmobleRequest $request, Immoble $immoble)
    {
        $validated = $request->validated();

        // Extract propietari data
        $propietaris = $this->propietarisDelFormulari($validated);

        unset(
            $validated['propietari_ids'],
            $validated['propietari_data_inici'],
            $validated['propietari_data_fi'],
            $validated['propietari_quota'],
        );

        $immoble->update($validated);

        $this->desaPropietaris($immoble, $propietaris);

        return redirect()->route('immobles.index')
            ->with('success', 'Immoble actualitzat correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Immoble $immoble)
    {
        $immoble->delete();

        return redirect()->route('immobles.index')
            ->with('success', 'Immoble eliminat correctament.');
    }

    /**
     * Les files de titularitat que arriben del formulari.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function propietarisDelFormulari(array $validated): array
    {
        $files = [];

        foreach ($validated['propietari_ids'] ?? [] as $i => $personaId) {
            $files[] = [
                'persona_id' => $personaId,
                'data_inici' => $validated['propietari_data_inici'][$i] ?? now()->toDateString(),
                'data_fi'    => $validated['propietari_data_fi'][$i] ?? null,
                'quota'      => $validated['propietari_quota'][$i] ?? null,
            ];
        }

        return $files;
    }

    /**
     * Desa les titularitats una per una, no amb sync().
     *
     * Una persona pot constar-hi **més d'un cop**: la seva quota canvia amb els anys i cada
     * tram és una fila amb les seves dates. Amb `sync()`, que va indexat per persona, el
     * segon tram esborrava el primer.
     *
     * L'amortització no és al formulari —es calcula a part i s'edita per tinker— i es
     * conserva buscant el tram que ja hi havia per persona i data d'inici.
     *
     * @param  array<int, array<string, mixed>>  $propietaris
     */
    private function desaPropietaris(Immoble $immoble, array $propietaris): void
    {
        $amortitzacions = $immoble->propietaris()
            ->get()
            ->mapWithKeys(function ($p) {
                $inici = $p->pivot->data_inici instanceof \DateTimeInterface
                    ? $p->pivot->data_inici->format('Y-m-d')
                    : substr((string) $p->pivot->data_inici, 0, 10);

                return [$p->id . '|' . $inici => $p->pivot->amortitzacio_anual];
            });

        $immoble->propietaris()->detach();

        foreach ($propietaris as $fila) {
            $immoble->propietaris()->attach($fila['persona_id'], [
                'data_inici'         => $fila['data_inici'],
                'data_fi'            => $fila['data_fi'],
                'quota'              => $fila['quota'],
                'amortitzacio_anual' => $amortitzacions[$fila['persona_id'] . '|' . $fila['data_inici']] ?? null,
            ]);
        }
    }
}
