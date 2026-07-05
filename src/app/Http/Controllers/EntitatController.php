<?php

namespace App\Http\Controllers;

use App\Http\Requests\EntitatRequest;
use App\Models\Entitat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EntitatController extends Controller
{
    public function index(): Response
    {
        $entitats = Entitat::withCount('comptesCorrents')
            ->orderBy('nom')
            ->get();

        return Inertia::render('Entitats/Index', [
            'entitats' => $entitats,
        ]);
    }

    public function store(EntitatRequest $request): JsonResponse
    {
        $entitat = Entitat::create($request->validated());
        return response()->json($entitat);
    }

    public function update(EntitatRequest $request, Entitat $entitat): JsonResponse
    {
        $entitat->update($request->validated());
        return response()->json($entitat);
    }

    public function destroy(Entitat $entitat): JsonResponse
    {
        if ($entitat->comptesCorrents()->exists()) {
            return response()->json(['error' => "No es pot eliminar: hi ha comptes associats a aquesta entitat."], 422);
        }
        $entitat->delete();
        return response()->json(['ok' => true]);
    }
}
