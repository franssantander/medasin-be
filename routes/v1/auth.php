<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->controller(AuthController::class)
    ->group(function (): void {
        Route::post('register', 'register')->name('register');
        Route::post('verify-email', 'verifyEmail')->name('verify-email');
        Route::post('resend-verification', 'resendVerification')->name('resend-verification');
        Route::post('forgot-password', 'forgotPassword')->name('forgot-password');
        Route::post('verify-password-reset', 'verifyPasswordReset')->name('verify-password-reset');
        Route::post('reset-password', 'resetPassword')->name('reset-password');
        Route::post('login', 'login')->name('login');
        Route::post('refresh', 'refresh')->name('refresh');

        Route::middleware('auth:api')->group(function (): void {
            Route::get('me', 'me')->middleware('verified.api')->name('me');
            Route::post('logout', 'logout')->name('logout');
        });
    });
