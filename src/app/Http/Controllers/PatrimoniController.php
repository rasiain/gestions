<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Services\PatrimoniService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PatrimoniController extends Controller
{
    public function __construct(
        private readonly PatrimoniService $patrimoni,
    ) {
    }

    public function index(Request $request)
    {
        // Només els titulars d'algun compte: la resta de persones —llogaters, proveïdors—
        // no tenen cap patrimoni que aquesta pantalla pugui calcular.
        //
        // L'ordre es fa aquí i no a la consulta perquè SQLite ordena per codi de caràcter
        // i posaria «GARCÍA» abans que «Garcia».
        $persones = Persona::has('comptesCorrents')
            ->get(['id', 'nom', 'cognoms'])
            ->sortBy(fn (Persona $p) => Str::lower(Str::ascii($p->cognoms . ' ' . $p->nom)))
            ->values();

        $personaId = $request->integer('persona_id') ?: $persones->first()?->id;
        $persona   = $personaId ? Persona::find($personaId) : null;

        // El patrimoni es valora el darrer dia de l'any: l'any en curs encara no ha
        // acabat i no es pot declarar. El darrer exercici possible és el passat.
        $maxim = (int) date('Y') - 1;
        $any   = min(max($request->integer('any') ?: $maxim, 1900), $maxim);

        return Inertia::render('Impostos/Patrimoni', [
            'persones'   => $persones->map(fn (Persona $p) => [
                'id'  => $p->id,
                'nom' => trim($p->cognoms . ', ' . $p->nom),
            ]),
            'personaId'  => $personaId,
            'any'        => $any,
            'anyMaxim'   => $maxim,
            'declaracio' => $persona ? $this->patrimoni->declaracio($persona, $any) : null,
        ]);
    }
}
