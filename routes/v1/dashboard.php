<?php

use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'show'])
    ->middleware('auth:api')
    ->name('dashboard.show');
