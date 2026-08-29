<?php

use App\Http\Controllers\Area\AreaController;
use App\Http\Controllers\Area\AreaGoalController;
use App\Http\Controllers\Area\AreaHabitController;
use App\Http\Controllers\Area\AreaNoteController;
use App\Http\Controllers\Area\AreaProjectController;
use App\Http\Controllers\Area\AreaResourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('area')
    ->name('area.')
    ->middleware('auth:api')
    ->controller(AreaController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{area}', 'show')->name('show');
        Route::put('/{area}', 'update')->name('update');
        Route::delete('/{area}', 'destroy')->name('destroy');
        Route::post('/{area}/archive', 'archive')->name('archive');
        Route::post('/{area}/restore', 'restore')->name('restore');
    });

Route::prefix('area/{area}')
    ->name('area.')
    ->middleware('auth:api')
    ->group(function () {
        Route::apiResource('goals', AreaGoalController::class);
        Route::apiResource('habits', AreaHabitController::class);
        Route::get('habits/{habit}/history', [AreaHabitController::class, 'history'])->name('habits.history');
        Route::put('habits/{habit}/check-ins/{date}', [AreaHabitController::class, 'checkIn'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('habits.check-ins.update');
        Route::get('notes/tree', [AreaNoteController::class, 'tree'])->name('notes.tree');
        Route::post('notes/{note}/media', [AreaNoteController::class, 'storeMedia'])->name('notes.media.store');
        Route::apiResource('notes', AreaNoteController::class);

        Route::get('projects', [AreaProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [AreaProjectController::class, 'store'])->name('projects.store');
        Route::delete('projects/{project}', [AreaProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('resources', [AreaResourceController::class, 'index'])->name('resources.index');
        Route::post('resources', [AreaResourceController::class, 'store'])->name('resources.store');
        Route::delete('resources/{resource}', [AreaResourceController::class, 'destroy'])->name('resources.destroy');
    });
