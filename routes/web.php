<?php

use App\Http\Controllers\CashFlowActualsForecastReportController;
use App\Http\Controllers\CashFlowReportController;
use App\Http\Controllers\GrossMarginReportController;
use App\Http\Controllers\AlloyDbGrossMarginController;
use App\Http\Controllers\AlloyDbOverdraftController;
use App\Http\Controllers\GrossMarginV2ReportController;
use App\Http\Controllers\MongoGrossMarginController;
use App\Http\Controllers\OverdraftReportController;
use App\Http\Controllers\TrackerCashFlowReportController;
use App\Http\Controllers\ValuationReportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'reports')->name('reports');
Route::get('/cashflow', CashFlowReportController::class)->name('cashflow');
Route::get('/cashflow-actuals-plus-forecast', CashFlowActualsForecastReportController::class)->name('cashflow-actuals-plus-forecast');
Route::get('/tracker-cashflow', TrackerCashFlowReportController::class)->name('tracker-cashflow');
Route::get('/gross-margin', GrossMarginReportController::class)->name('gross-margin');
Route::get('/gross-margin-v2', GrossMarginV2ReportController::class)->name('gross-margin-v2');
Route::get('/alloydb/gross-margin', AlloyDbGrossMarginController::class)->name('alloydb-gross-margin');
Route::get('/mongo/gross-margin', MongoGrossMarginController::class)->name('mongo-gross-margin');
Route::get('/overdraft', OverdraftReportController::class)->name('overdraft');
Route::post('/overdraft/config', [OverdraftReportController::class, 'save'])->name('overdraft.save');
Route::get('/alloydb/overdraft', AlloyDbOverdraftController::class)->name('alloydb-overdraft');
Route::post('/alloydb/overdraft/config', [AlloyDbOverdraftController::class, 'save'])->name('alloydb-overdraft.save');
Route::get('/valuation', ValuationReportController::class)->name('valuation');
