<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalutRegistreRequest;
use App\Models\Preferencia;
use App\Models\SalutRegistre;
use App\Services\DadesFisiquesService;
use App\Services\EstatFormaService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Les dades físiques de l'usuari: el perfil (data de naixement i sexe, un sol cop) i els registres per
 * dates (pes, pulsació en repòs i màxima), que són el que necessita el càlcul de l'estat de forma.
 */
class SalutController extends Controller
{
    public function dadesFisiques(DadesFisiquesService $dades)
    {
        $registres = SalutRegistre::orderByDesc('data')->get()->map(fn (SalutRegistre $r) => [
            'id'        => $r->id,
            'data'      => $r->data->toDateString(),
            'pes'       => $r->pes,
            'fc_repos'  => $r->fc_repos,
            'fc_maxima' => $r->fc_maxima,
            // La que s'aplicaria si no se n'apuntés cap: perquè es vegi al costat de la buida
            'fc_maxima_deduida' => $dades->fcMaximaDeduida($r->data),
            'notes'     => $r->notes,
            'origen'    => $r->origen,
        ]);

        return Inertia::render('Salut/DadesFisiques', [
            'registres' => $registres,
            'perfil'    => $dades->perfil(),
            'avui'      => [
                'pes'       => $dades->valorA('pes', now()),
                'fc_repos'  => $dades->valorA('fc_repos', now()),
                'fc_maxima' => $dades->fcMaxima(now()),
            ],
        ]);
    }

    /**
     * La càrrega de cada sortida i les corbes de fons, fatiga i frescor, des de 2024. Es calcula des de
     * tres mesos abans perquè el fons hi arribi escalfat; la tria del rang es fa a la pantalla.
     */
    public function estatForma(EstatFormaService $forma)
    {
        $escalfament = Carbon::parse(EstatFormaService::ESCALFAMENT);
        $desDe = Carbon::parse(EstatFormaService::DES_DE);
        $carregues = $forma->carregues($escalfament);

        return Inertia::render('Salut/EstatForma', [
            'corbes'    => $forma->corbes($carregues, $escalfament, $desDe, today()),
            'sortides'  => array_values(array_filter($carregues, fn ($c) => $c['data'] >= EstatFormaService::DES_DE)),
            'constants' => ['fons' => EstatFormaService::DIES_FONS, 'fatiga' => EstatFormaService::DIES_FATIGA],
        ]);
    }

    public function store(SalutRegistreRequest $request)
    {
        SalutRegistre::create($request->validated() + ['origen' => 'manual']);

        return back();
    }

    public function update(SalutRegistreRequest $request, SalutRegistre $registre)
    {
        // Un registre de la càrrega inicial que es corregeix a mà passa a ser manual: la càrrega ja no el tocarà
        $registre->update($request->validated() + ['origen' => 'manual']);

        return back();
    }

    public function destroy(SalutRegistre $registre)
    {
        $registre->delete();

        return back();
    }

    public function perfil(Request $request)
    {
        $perfil = $request->validate([
            'data_naixement' => ['nullable', 'date', 'before:today'],
            'sexe'           => ['nullable', 'in:home,dona'],
            'alcada'         => ['nullable', 'integer', 'between:100,230'],
        ], [
            'alcada.between' => "L'alçada va en centímetres, entre 100 i 230.",
        ]);
        Preferencia::desa(DadesFisiquesService::PREFERENCIA_PERFIL, $perfil);

        return back();
    }
}
