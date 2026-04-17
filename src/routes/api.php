<?php

use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\CompteCorrentController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MovimentController;
use Illuminate\Support\Facades\Route;

Route::middleware('agent.token')->group(function () {
    Route::get('/comptes-corrents', [CompteCorrentController::class, 'index']);

    Route::post('/import/auto-parse', [ImportController::class, 'autoParse']);
    Route::post('/import/parse', [ImportController::class, 'parse']);
    Route::post('/import/store', [ImportController::class, 'store']);

    Route::get('/moviments', [MovimentController::class, 'index']);
    Route::patch('/moviments/{movimentId}/categoria', [MovimentController::class, 'updateCategoria']);
    Route::post('/moviments/classifica-multiple', [MovimentController::class, 'bulkClassifica']);

    Route::get('/categories', [CategoriaController::class, 'index']);
    Route::post('/categories', [CategoriaController::class, 'store']);
});
