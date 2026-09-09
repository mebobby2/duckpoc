<?php

use App\Http\Controllers\CashFlowReportController;
use App\Http\Controllers\GrossMarginReportController;
use App\Http\Controllers\TrackerCashFlowReportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'reports')->name('reports');
Route::get('/cashflow', CashFlowReportController::class)->name('cashflow');
Route::get('/tracker-cashflow', TrackerCashFlowReportController::class)->name('tracker-cashflow');
Route::get('/gross-margin', GrossMarginReportController::class)->name('gross-margin');
