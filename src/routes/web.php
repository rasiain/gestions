<?php

use App\Http\Controllers\ArrendadorController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CategoryImportController;
use App\Http\Controllers\CompteCorrentController;
use App\Http\Controllers\ComunitatBensController;
use App\Http\Controllers\ImmobleController;
use App\Http\Controllers\LlogaterController;
use App\Http\Controllers\ContracteController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\LlibreIvaController;
use App\Http\Controllers\LloguerController;
use App\Http\Controllers\LloguerRevisioIpcController;
use App\Http\Controllers\MovementImportController;
use App\Http\Controllers\ImpostosIrpfController;
use App\Http\Controllers\ImpostosIvaController;
use App\Http\Controllers\MovimentClassificacioController;
use App\Http\Controllers\MovimentCompteCorrentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\ProveidorController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Persones management
    Route::resource('persones', PersonaController::class)->only([
        'index', 'store', 'update', 'destroy'
    ])->parameters(['persones' => 'persona']);

    // Immobles management
    Route::resource('immobles', ImmobleController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);

    // Lloguers management
    Route::resource('lloguers', LloguerController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);
    Route::get('/lloguers/{lloguer}/moviments', [LloguerController::class, 'moviments'])->name('lloguers.moviments');
    Route::get('/lloguers/{lloguer}/exportar', [LloguerController::class, 'exportar'])->name('lloguers.exportar');
    Route::get('/lloguers/{lloguer}/exportar-llibre-iva', [LlibreIvaController::class, 'exportar'])->name('lloguers.exportar-llibre-iva');
    Route::get('/lloguers/{lloguer}/resum', [LloguerController::class, 'resum'])->name('lloguers.resum');

    // Factures
    Route::get('/lloguers/{lloguer}/factures', [FacturaController::class, 'index']);
    Route::post('/lloguers/{lloguer}/factures', [FacturaController::class, 'store']);
    Route::post('/lloguers/{lloguer}/factures/generar', [FacturaController::class, 'generar']);
    Route::put('/factures/{factura}', [FacturaController::class, 'update']);
    Route::delete('/factures/{factura}', [FacturaController::class, 'destroy']);
    Route::post('/factures/{factura}/vincular-moviment', [FacturaController::class, 'vincularMoviment']);

    // Revisions IPC
    Route::get('/lloguers/{lloguer}/revisions-ipc', [LloguerRevisioIpcController::class, 'index']);
    Route::post('/lloguers/{lloguer}/revisions-ipc', [LloguerRevisioIpcController::class, 'store']);

    // Contractes management
    Route::resource('contractes', ContracteController::class)->only([
        'store', 'update', 'destroy'
    ]);

    // Llogaters management
    Route::resource('llogaters', LlogaterController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);

    // Proveidors management
    Route::resource('proveidors', ProveidorController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);

    // Comunitats de Béns management
    Route::resource('comunitats-bens', ComunitatBensController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);

    // Arrendadors management
    Route::resource('arrendadors', ArrendadorController::class)->only([
        'store', 'update', 'destroy'
    ]);

    // Comptes corrents management
    Route::resource('comptes-corrents', CompteCorrentController::class)
        ->parameters(['comptes-corrents' => 'compte_corrent'])
        ->only([
            'index', 'store', 'update', 'destroy'
        ]);

    // Categories management
    Route::resource('categories', CategoriaController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);

    // Moviments management
    Route::resource('moviments', MovimentCompteCorrentController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);
    Route::patch('/moviments/{moviment}/exclou-lloguer', [MovimentCompteCorrentController::class, 'toggleExclou'])->name('moviments.toggle-exclou');
    Route::post('/moviments/{moviment}/classificacio', [MovimentClassificacioController::class, 'store'])->name('moviments.classificacio.store');
    Route::put('/moviments/{moviment}/classificacio', [MovimentClassificacioController::class, 'update'])->name('moviments.classificacio.update');
    Route::delete('/moviments/{moviment}/classificacio', [MovimentClassificacioController::class, 'destroy'])->name('moviments.classificacio.destroy');

    // Impostos
    Route::get('/impostos/irpf', [ImpostosIrpfController::class, 'index'])->name('impostos.irpf');
    Route::get('/impostos/iva', [ImpostosIvaController::class, 'index'])->name('impostos.iva');

    // Maintenance - Movement Import
    Route::get('/maintenance/movements/import', [MovementImportController::class, 'index'])->name('maintenance.movements.import');
    Route::post('/maintenance/movements/import/parse', [MovementImportController::class, 'parse'])->name('maintenance.movements.import.parse');
    Route::post('/maintenance/movements/import', [MovementImportController::class, 'import'])->name('maintenance.movements.import.store');
});

require __DIR__.'/auth.php';
