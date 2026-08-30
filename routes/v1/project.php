<?php

use App\Http\Controllers\Project\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('project')
    ->name('project.')
    ->middleware('auth:api')
    ->controller(ProjectController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{project}', 'show')->name('show');
        Route::put('/{project}', 'update')->name('update');
        Route::delete('/{project}', 'destroy')->name('destroy');
        Route::post('/{project}/archive', 'archive')->name('archive');
        Route::post('/{project}/restore', 'restore')->name('restore');
    });
