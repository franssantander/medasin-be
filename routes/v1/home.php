<?php

use App\Http\Controllers\Home\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('home', [HomeController::class, 'show'])
    ->middleware('auth:api')
    ->name('home.show');
