<?php

use App\Http\Controllers\Iclock\DeviceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('iclock')->name('iclock.')->group(function (): void {
    Route::get('/cdata', [DeviceController::class, 'initialize'])->name('initialize');
    Route::post('/cdata', [DeviceController::class, 'upload'])->name('upload');
    Route::get('/getrequest', [DeviceController::class, 'poll'])->name('poll');
    Route::post('/devicecmd', [DeviceController::class, 'result'])->name('result');
    Route::get('/ping', [DeviceController::class, 'ping'])->name('ping');
});
