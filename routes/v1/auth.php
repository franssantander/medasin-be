<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->controller(AuthController::class)
    ->group(function () {
        Route::post('login', 'login')->name('login');
        Route::post('refresh', 'refresh')->name('refresh');

        Route::middleware('auth:api')->group(function () {
            Route::get('me', 'me')->name('me');
            Route::post('logut', 'logout')->name('logout');
        });
    });