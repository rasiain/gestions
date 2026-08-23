<?php

namespace App\Http\Controllers;

use App\Http\Requests\Model184PropietariRequest;
use App\Models\ComunitatBens;
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

        return Inertia::render('Impostos/Model184', [
            'comunitats'      => $comunitats,
            'comunitatId'     => $comunitatId,
            'any'             => $any,
            'anysAmbFactures' => $anysAmbFactures,
            'declaracio'      => $comunitat ? $this->model184->declaracio($comunitat, $any) : null,
            'caselles'        => Model184Service::CASELLES,
        ]);
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
