<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExcelController;

Route::post('/excel/process-transactions', [ExcelController::class, 'processTransactions']);
Route::get('/excel/supported-formats', [ExcelController::class, 'getSupportedFormats']);
