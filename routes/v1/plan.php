<?php

use App\Http\Controllers\Plan\PlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('plan')
    ->name('plan.')
    ->controller(PlanController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
    });