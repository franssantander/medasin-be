<?php

use App\Http\Controllers\Project\ProjectBoardController;
use App\Http\Controllers\Project\ProjectBoardLabelController;
use App\Http\Controllers\Project\ProjectBoardTaskController;
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
        Route::patch('/{project}/area', 'updateArea')->name('area.update');
        Route::post('/{project}/resources', 'attachResources')->name('resources.store');
        Route::delete('/{project}/resources/{resource}', 'detachResource')->name('resources.destroy');
        Route::delete('/{project}', 'destroy')->name('destroy');
        Route::post('/{project}/archive', 'archive')->name('archive');
        Route::post('/{project}/restore', 'restore')->name('restore');
    });

Route::prefix('project/{project}/boards')
    ->name('project.boards.')
    ->middleware('auth:api')
    ->group(function () {
        Route::get('/', [ProjectBoardController::class, 'index'])->name('index');
        Route::post('/', [ProjectBoardController::class, 'store'])->name('store');
        Route::get('/{board}', [ProjectBoardController::class, 'show'])->name('show');
        Route::put('/{board}', [ProjectBoardController::class, 'update'])->name('update');
        Route::delete('/{board}', [ProjectBoardController::class, 'destroy'])->name('destroy');

        Route::get('/{board}/labels', [ProjectBoardLabelController::class, 'index'])->name('labels.index');
        Route::post('/{board}/labels', [ProjectBoardLabelController::class, 'store'])->name('labels.store');
        Route::put('/{board}/labels/{label}', [ProjectBoardLabelController::class, 'update'])->name('labels.update');
        Route::delete('/{board}/labels/{label}', [ProjectBoardLabelController::class, 'destroy'])->name('labels.destroy');

        Route::get('/{board}/tasks', [ProjectBoardTaskController::class, 'index'])->name('tasks.index');
        Route::post('/{board}/tasks', [ProjectBoardTaskController::class, 'store'])->name('tasks.store');
        Route::get('/{board}/tasks/{task}', [ProjectBoardTaskController::class, 'show'])->name('tasks.show');
        Route::put('/{board}/tasks/{task}', [ProjectBoardTaskController::class, 'update'])->name('tasks.update');
        Route::delete('/{board}/tasks/{task}', [ProjectBoardTaskController::class, 'destroy'])->name('tasks.destroy');
        Route::patch('/{board}/tasks/{task}/move', [ProjectBoardTaskController::class, 'move'])->name('tasks.move');
    });
