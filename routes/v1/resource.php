<?php

use App\Http\Controllers\Resource\ResourceController;
use Illuminate\Support\Facades\Route;

Route::get('resource', [ResourceController::class, 'index'])
    ->middleware('auth:api')
    ->name('resource.index');
