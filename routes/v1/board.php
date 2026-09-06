<?php

use App\Http\Controllers\Board\StandaloneBoardController;
use App\Http\Controllers\Board\StandaloneBoardLabelController;
use App\Http\Controllers\Board\StandaloneBoardTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('board')->name('board.')->middleware('auth:api')->group(function () {
    Route::get('/', [StandaloneBoardController::class, 'index'])->name('index');
    Route::post('/', [StandaloneBoardController::class, 'store'])->name('store');
    Route::get('/{board}', [StandaloneBoardController::class, 'show'])->name('show');
    Route::put('/{board}', [StandaloneBoardController::class, 'update'])->name('update');
    Route::delete('/{board}', [StandaloneBoardController::class, 'destroy'])->name('destroy');

    Route::post('/{board}/labels', [StandaloneBoardLabelController::class, 'store'])->name('labels.store');
    Route::put('/{board}/labels/{label}', [StandaloneBoardLabelController::class, 'update'])->name('labels.update');
    Route::delete('/{board}/labels/{label}', [StandaloneBoardLabelController::class, 'destroy'])->name('labels.destroy');

    Route::post('/{board}/tasks', [StandaloneBoardTaskController::class, 'store'])->name('tasks.store');
    Route::put('/{board}/tasks/{task}', [StandaloneBoardTaskController::class, 'update'])->name('tasks.update');
    Route::delete('/{board}/tasks/{task}', [StandaloneBoardTaskController::class, 'destroy'])->name('tasks.destroy');
    Route::patch('/{board}/tasks/{task}/move', [StandaloneBoardTaskController::class, 'move'])->name('tasks.move');
});
