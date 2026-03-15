<?php

namespace App\Http\Controllers;

use App\Http\Requests\LlogaterRequest;
use App\Models\Llogater;
use Inertia\Inertia;

class LlogaterController extends Controller
{
    public function index()
    {
        $llogaters = Llogater::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        return Inertia::render('Llogaters/Index', [
            'llogaters' => $llogaters,
        ]);
    }

    public function store(LlogaterRequest $request)
    {
        Llogater::create($request->validated());

        return redirect()->route('llogaters.index')
            ->with('success', 'Llogater creat correctament.');
    }

    public function update(LlogaterRequest $request, Llogater $llogater)
    {
        $llogater->update($request->validated());

        return redirect()->route('llogaters.index')
            ->with('success', 'Llogater actualitzat correctament.');
    }

    public function destroy(Llogater $llogater)
    {
        $llogater->delete();

        return redirect()->route('llogaters.index')
            ->with('success', 'Llogater eliminat correctament.');
    }
}
