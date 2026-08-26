<?php

namespace App\Http\Controllers;

use App\Http\Requests\Model184PropietariRequest;
use App\Models\ComunitatBens;
use App\Models\Model184Declaracio;
use App\Services\Model184Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class Model184Controller extends Controller
{
    public function __construct(
        private readonly Model184Service $model184,
    ) {
    }

    public function index(Request $request)
    {
        $comunitats = ComunitatBens::orderBy('nom')->get(['id', 'nom', 'nif']);

        $comunitatId = $request->integer('comunitat_bens_id') ?: $comunitats->first()?->id;
        $comunitat   = $comunitatId ? ComunitatBens::find($comunitatId) : null;

        $anysAmbFactures = $comunitat ? $this->model184->anysAmbFactures($comunitat) : [];

        // La declaració es presenta el gener següent, o sigui que l'exercici natural és
        // l'any passat; però obrir en un any sense cap factura no diu res a ningú, i
        // llavors val més el darrer que en tingui.
        $any = $request->integer('any')
            ?: (in_array((int) date('Y') - 1, $anysAmbFactures, true)
                ? (int) date('Y') - 1
                : ($anysAmbFactures[0] ?? (int) date('Y') - 1));

        $calcul     = $comunitat ? $this->model184->declaracio($comunitat, $any) : null;
        $declarada  = $comunitat ? $this->model184->materialitzada($comunitat, $any) : null;

        return Inertia::render('Impostos/Model184', [
            'comunitats'      => $comunitats,
            'comunitatId'     => $comunitatId,
            'any'             => $any,
            'anysAmbFactures' => $anysAmbFactures,
            'declaracio'      => $calcul,
            'caselles'        => Model184Service::CASELLES,
            'declarada'       => $declarada ? [
                'id'                   => $declarada->id,
                'numero_identificatiu' => $declarada->numero_identificatiu,
                'notes'                => $declarada->notes,
                'materialitzada_el'    => $declarada->materialitzada_el->toDateTimeString(),
                'retencions'           => (float) $declarada->retencions,
                'immobles'             => $declarada->immobles->map(fn ($i) => [
                    'lloguer'              => $i->lloguer_nom,
                    'referencia_cadastral' => $i->referencia_cadastral,
                    'ingressos'            => (float) $i->ingressos,
                    'despeses'             => (float) $i->despeses,
                    'rendiment_net'        => (float) $i->rendiment_net,
                    'retencions'           => (float) $i->retencions,
                    'amortitzacio'         => (float) $i->amortitzacio,
                    'caselles'             => $i->caselles->pluck('import', 'casella')->map(fn ($v) => (float) $v),
                    'comuners'             => $i->comuners->map(fn ($c) => [
                        'nom'          => $c->nom,
                        'nif'          => $c->nif,
                        'quota'        => $c->quota !== null ? (float) $c->quota : null,
                        'amortitzacio' => (float) $c->amortitzacio,
                        'rendiment'    => $c->rendiment !== null ? (float) $c->rendiment : null,
                        'participacio' => $c->participacio !== null ? (float) $c->participacio : null,
                        'retencio'     => $c->retencio !== null ? (float) $c->retencio : null,
                    ]),
                ]),
            ] : null,
            // Si difereixen, val la pena saber-ho abans de mirar-se els números
            'diferencies'     => $declarada && $calcul ? $this->model184->diferencies($declarada, $calcul) : [],
        ]);
    }

    /**
     * Congela la declaració de l'exercici tal com es calcula avui.
     */
    public function materialitza(Request $request)
    {
        $comunitat = ComunitatBens::findOrFail($request->integer('comunitat_bens_id'));
        $any       = $request->integer('any');

        $this->model184->materialitza($comunitat, $any);

        return back();
    }

    /**
     * Dades que només se saben un cop presentada.
     */
    public function updateDeclaracio(Request $request, Model184Declaracio $declaracio)
    {
        $declaracio->update($request->validate([
            'numero_identificatiu' => ['nullable', 'string', 'max:40'],
            'notes'                => ['nullable', 'string', 'max:2000'],
        ]));

        return back();
    }

    /**
     * Descongela: només per refer-la, no per corregir-la a mitges.
     */
    public function destroyDeclaracio(Model184Declaracio $declaracio)
    {
        $declaracio->delete();

        return back();
    }

    /**
     * Quota i amortització d'un comuner. Van al pivot d'immoble ↔ persona, a la fila
     * vigent durant l'exercici: si el comuner canvia, la fila nova no arrossega res.
     */
    public function updatePropietari(Model184PropietariRequest $request)
    {
        $any   = $request->integer('any');
        $inici = sprintf('%04d-01-01', $any);
        $fi    = sprintf('%04d-12-31', $any);

        $files = DB::table('g_propietaris_immobles')
            ->where('immoble_id', $request->integer('immoble_id'))
            ->where('persona_id', $request->integer('persona_id'))
            ->where('data_inici', '<=', $fi)
            ->where(fn ($q) => $q->whereNull('data_fi')->orWhere('data_fi', '>=', $inici))
            ->update([
                'quota'              => $request->input('quota'),
                'amortitzacio_anual' => $request->input('amortitzacio_anual'),
                'updated_at'         => now(),
            ]);

        if ($files === 0) {
            return back()->withErrors(['persona_id' => "Aquesta persona no consta com a propietària de l'immoble durant {$any}."]);
        }

        return back();
    }
}
