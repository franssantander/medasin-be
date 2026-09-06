<?php

use App\Http\Controllers\Focus\FocusController;
use App\Http\Controllers\Focus\FocusSessionController;
use App\Http\Controllers\Focus\FocusTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('focus')->name('focus.')->middleware('auth:api')->group(function () {
    Route::get('/', [FocusController::class, 'show'])->name('show');
    Route::get('/linkable-tasks', [FocusController::class, 'linkableTasks'])->name('linkable-tasks');
    Route::put('/settings', [FocusController::class, 'updateSettings'])->name('settings.update');

    Route::get('/tasks', [FocusTaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [FocusTaskController::class, 'store'])->name('tasks.store');
    Route::patch('/tasks/{focusTask}', [FocusTaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{focusTask}', [FocusTaskController::class, 'destroy'])->name('tasks.destroy');

    Route::post('/sessions', [FocusSessionController::class, 'store'])->name('sessions.store');
    Route::post('/sessions/{focusSession}/pause', [FocusSessionController::class, 'pause'])->name('sessions.pause');
    Route::post('/sessions/{focusSession}/resume', [FocusSessionController::class, 'resume'])->name('sessions.resume');
    Route::post('/sessions/{focusSession}/complete', [FocusSessionController::class, 'complete'])->name('sessions.complete');
    Route::post('/sessions/{focusSession}/cancel', [FocusSessionController::class, 'cancel'])->name('sessions.cancel');
    Route::put('/sessions/{focusSession}/reflection', [FocusSessionController::class, 'reflection'])->name('sessions.reflection');
});
