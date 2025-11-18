<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DataFileController;

Route::post('/data/process', [DataFileController::class, 'process']);
Route::get('/data/supported-formats', [DataFileController::class, 'getSupportedFormats']);
