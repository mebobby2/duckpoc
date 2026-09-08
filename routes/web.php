<?php

use App\Http\Controllers\CashFlowReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', CashFlowReportController::class);
Route::get('/cashflow', CashFlowReportController::class)->name('cashflow');
