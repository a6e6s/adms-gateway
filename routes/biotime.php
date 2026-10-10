<?php

use App\Http\Controllers\BioTime\TokenController;
use App\Http\Controllers\BioTime\TransactionController;
use App\Http\Middleware\AuthenticateBioTimeClient;
use Illuminate\Support\Facades\Route;

Route::post('/jwt-api-token-auth/', TokenController::class)->middleware('throttle:biotime-login')->name('biotime.token');

Route::prefix('iclock/api')->middleware([AuthenticateBioTimeClient::class, 'throttle:biotime-read'])->name('biotime.')->group(function (): void {
    Route::get('/transactions/', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/{id}/', [TransactionController::class, 'show'])->whereNumber('id')->name('transactions.show');
});
