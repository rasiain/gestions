<?php

use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CategoryImportController;
use App\Http\Controllers\CompteCorrentController;
use App\Http\Controllers\MovementImportController;
use App\Http\Controllers\MovimentCompteCorrentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PersonaController;
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

    // Maintenance - Movement Import
    Route::get('/maintenance/movements/import', [MovementImportController::class, 'index'])->name('maintenance.movements.import');
    Route::post('/maintenance/movements/import/parse', [MovementImportController::class, 'parse'])->name('maintenance.movements.import.parse');
    Route::post('/maintenance/movements/import', [MovementImportController::class, 'import'])->name('maintenance.movements.import.store');
});

require __DIR__.'/auth.php';
