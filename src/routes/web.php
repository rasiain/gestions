<?php

use App\Http\Controllers\ArrendadorController;
use App\Http\Controllers\EntitatController;
use App\Http\Controllers\FonsInversioController;
use App\Http\Controllers\PlaPensionsController;
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
use App\Http\Controllers\TipusDespesaFiscalController;
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
    Route::post('/lloguers/{lloguer}/desar-export', [LloguerController::class, 'desarExport'])->name('lloguers.desar-export');
    Route::get('/lloguers/{lloguer}/exportar', [LloguerController::class, 'exportar'])->name('lloguers.exportar');
    Route::post('/lloguers/{lloguer}/desar-llibre-iva', [LlibreIvaController::class, 'desar'])->name('lloguers.desar-llibre-iva');
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

    // Entitats bancàries
    Route::get('/entitats', [EntitatController::class, 'index'])->name('entitats.index');
    Route::post('/entitats', [EntitatController::class, 'store'])->name('entitats.store');
    Route::put('/entitats/{entitat}', [EntitatController::class, 'update'])->name('entitats.update');
    Route::delete('/entitats/{entitat}', [EntitatController::class, 'destroy'])->name('entitats.destroy');

    // Comptes corrents management
    Route::resource('comptes-corrents', CompteCorrentController::class)
        ->parameters(['comptes-corrents' => 'compte_corrent'])
        ->only([
            'index', 'store', 'update', 'destroy'
        ]);
    Route::get('/comptes-corrents/{compte_corrent}/balanc', [CompteCorrentController::class, 'balanc'])
        ->name('comptes-corrents.balanc');

    // Categories management
    Route::get('/categories/{category}/totals', [CategoriaController::class, 'totals'])->name('categories.totals');
    Route::get('/categories/{category}/moviments', [CategoriaController::class, 'moviments'])->name('categories.moviments');
    Route::resource('categories', CategoriaController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);

    // Moviments management
    Route::post('/moviments/duplicar', [MovimentCompteCorrentController::class, 'duplicar'])->name('moviments.duplicar');
    Route::post('/moviments/edicio-multiple', [MovimentCompteCorrentController::class, 'bulkEdit'])->name('moviments.bulk-edit');
    Route::get('/moviments/verifica-saldos', [MovimentCompteCorrentController::class, 'verificaSaldos'])->name('moviments.verifica-saldos');
    Route::post('/moviments/suggeriments-categoria', [MovimentCompteCorrentController::class, 'suggerimentsCategoria'])->name('moviments.suggeriments-categoria');
    Route::resource('moviments', MovimentCompteCorrentController::class)->only([
        'index', 'store', 'update', 'destroy'
    ]);
    Route::patch('/moviments/{moviment}/exclou-lloguer', [MovimentCompteCorrentController::class, 'toggleExclou'])->name('moviments.toggle-exclou');
    Route::patch('/moviments/{moviment}/conciliat', [MovimentCompteCorrentController::class, 'toggleConciliat'])->name('moviments.toggle-conciliat');
    Route::post('/moviments/concilia-multiple', [MovimentCompteCorrentController::class, 'bulkConciliar'])->name('moviments.bulk-conciliar');
    Route::post('/moviments/classificacio-multiple', [MovimentClassificacioController::class, 'bulkUpdate'])->name('moviments.classificacio.bulk');
    Route::post('/moviments/{moviment}/classificacio', [MovimentClassificacioController::class, 'store'])->name('moviments.classificacio.store');
    Route::put('/moviments/{moviment}/classificacio', [MovimentClassificacioController::class, 'update'])->name('moviments.classificacio.update');
    Route::delete('/moviments/{moviment}/classificacio', [MovimentClassificacioController::class, 'destroy'])->name('moviments.classificacio.destroy');

    // Impostos
    Route::get('/impostos/irpf', [ImpostosIrpfController::class, 'index'])->name('impostos.irpf');
    Route::get('/impostos/iva', [ImpostosIvaController::class, 'index'])->name('impostos.iva');
    Route::get('/impostos/tipus-despesa-fiscal', [TipusDespesaFiscalController::class, 'index'])->name('impostos.tipus-despesa-fiscal');
    Route::put('/impostos/tipus-despesa-fiscal', [TipusDespesaFiscalController::class, 'updateMapping'])->name('impostos.tipus-despesa-fiscal.update');

    // Fons d'inversió
    Route::resource('fons-inversio', FonsInversioController::class)->only([
        'index', 'store', 'update', 'destroy'
    ])->parameters(['fons-inversio' => 'fonsInversio']);
    Route::post('/fons-inversio/contractes', [FonsInversioController::class, 'storeContracte'])->name('fons-inversio.contractes.store');
    Route::put('/fons-inversio/contractes/{contracte}', [FonsInversioController::class, 'updateContracte'])->name('fons-inversio.contractes.update');
    Route::delete('/fons-inversio/contractes/{contracte}', [FonsInversioController::class, 'destroyContracte'])->name('fons-inversio.contractes.destroy');
    Route::post('/fons-inversio/aportacions', [FonsInversioController::class, 'storeAportacio'])->name('fons-inversio.aportacions.store');
    Route::put('/fons-inversio/aportacions/{aportacio}', [FonsInversioController::class, 'updateAportacio'])->name('fons-inversio.aportacions.update');
    Route::delete('/fons-inversio/aportacions/{aportacio}', [FonsInversioController::class, 'destroyAportacio'])->name('fons-inversio.aportacions.destroy');
    Route::post('/fons-inversio/valors', [FonsInversioController::class, 'storeValor'])->name('fons-inversio.valors.store');
    Route::put('/fons-inversio/valors/{valor}', [FonsInversioController::class, 'updateValor'])->name('fons-inversio.valors.update');
    Route::delete('/fons-inversio/valors/{valor}', [FonsInversioController::class, 'destroyValor'])->name('fons-inversio.valors.destroy');
    Route::post('/fons-inversio/valors/import/parse', [FonsInversioController::class, 'parseValorsImport'])->name('fons-inversio.valors.import.parse');
    Route::post('/fons-inversio/valors/import', [FonsInversioController::class, 'storeValorsImport'])->name('fons-inversio.valors.import.store');
    Route::post('/fons-inversio/despeses', [FonsInversioController::class, 'storeDespesa'])->name('fons-inversio.despeses.store');
    Route::put('/fons-inversio/despeses/{despesa}', [FonsInversioController::class, 'updateDespesa'])->name('fons-inversio.despeses.update');
    Route::delete('/fons-inversio/despeses/{despesa}', [FonsInversioController::class, 'destroyDespesa'])->name('fons-inversio.despeses.destroy');

    // Plans de pensions
    Route::resource('plans-pensions', PlaPensionsController::class)->only([
        'index', 'store', 'update', 'destroy'
    ])->parameters(['plans-pensions' => 'plaPensions']);
    Route::post('/plans-pensions/contractes', [PlaPensionsController::class, 'storeContracte'])->name('plans-pensions.contractes.store');
    Route::put('/plans-pensions/contractes/{contracte}', [PlaPensionsController::class, 'updateContracte'])->name('plans-pensions.contractes.update');
    Route::delete('/plans-pensions/contractes/{contracte}', [PlaPensionsController::class, 'destroyContracte'])->name('plans-pensions.contractes.destroy');
    Route::post('/plans-pensions/aportacions', [PlaPensionsController::class, 'storeAportacio'])->name('plans-pensions.aportacions.store');
    Route::put('/plans-pensions/aportacions/{aportacio}', [PlaPensionsController::class, 'updateAportacio'])->name('plans-pensions.aportacions.update');
    Route::delete('/plans-pensions/aportacions/{aportacio}', [PlaPensionsController::class, 'destroyAportacio'])->name('plans-pensions.aportacions.destroy');
    Route::post('/plans-pensions/valors', [PlaPensionsController::class, 'storeValor'])->name('plans-pensions.valors.store');
    Route::put('/plans-pensions/valors/{valor}', [PlaPensionsController::class, 'updateValor'])->name('plans-pensions.valors.update');
    Route::delete('/plans-pensions/valors/{valor}', [PlaPensionsController::class, 'destroyValor'])->name('plans-pensions.valors.destroy');
    Route::post('/plans-pensions/despeses', [PlaPensionsController::class, 'storeDespesa'])->name('plans-pensions.despeses.store');
    Route::put('/plans-pensions/despeses/{despesa}', [PlaPensionsController::class, 'updateDespesa'])->name('plans-pensions.despeses.update');
    Route::delete('/plans-pensions/despeses/{despesa}', [PlaPensionsController::class, 'destroyDespesa'])->name('plans-pensions.despeses.destroy');

    // Maintenance - Movement Import
    Route::get('/maintenance/movements/import', [MovementImportController::class, 'index'])->name('maintenance.movements.import');
    Route::post('/maintenance/movements/import/parse', [MovementImportController::class, 'parse'])->name('maintenance.movements.import.parse');
    Route::post('/maintenance/movements/import', [MovementImportController::class, 'import'])->name('maintenance.movements.import.store');

    // Importació automàtica (scan → preview → import)
    Route::get('/importar', fn () => Inertia::render('Moviments/Import'))->name('importar.index');
    Route::get('/importar/scan', [MovementImportController::class, 'scan'])->name('importar.scan');
    Route::post('/importar/preview', [MovementImportController::class, 'autoPreview'])->name('importar.preview');
    Route::post('/importar/store', [MovementImportController::class, 'autoImport'])->name('importar.store');
});

require __DIR__.'/auth.php';
