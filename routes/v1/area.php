<?php

use App\Http\Controllers\Area\AreaController;
use Illuminate\Support\Facades\Route;

Route::prefix('area')
    ->name('area.')
    ->middleware('auth:api')
    ->controller(AreaController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{area}', 'show')->name('store');
        Route::put('/{area}', 'update')->name('update');
        Route::delete('/{area}', 'destroy')->name('destroy');
    });