<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompteCorrent;
use Illuminate\Http\JsonResponse;

class CompteCorrentController extends Controller
{
    public function index(): JsonResponse
    {
        $comptes = CompteCorrent::orderBy('ordre')
            ->orderBy('entitat')
            ->get()
            ->map(fn (CompteCorrent $compte) => [
                'id' => $compte->id,
                'nom' => $compte->nom,
                'entitat' => $compte->entitat,
                'iban' => $compte->compte_corrent,
                'bank_type' => $compte->bank_type,
                'saldo_actual' => $compte->saldo_actual,
                'last_import_type' => $compte->last_import_type,
            ]);

        return response()->json([
            'success' => true,
            'data' => $comptes,
        ]);
    }
}
