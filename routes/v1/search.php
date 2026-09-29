<?php

use App\Http\Controllers\Search\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('search', SearchController::class)
    ->middleware(['auth:api', 'throttle:60,1'])
    ->name('search.index');
