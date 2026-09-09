<?php

use App\Http\Controllers\Note\StandaloneNoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('notes')->name('notes.')->middleware('auth:api')->group(function () {
    Route::get('/', [StandaloneNoteController::class, 'index'])->name('index');
    Route::post('/', [StandaloneNoteController::class, 'store'])->name('store');
    Route::get('/tree', [StandaloneNoteController::class, 'tree'])->name('tree');
    Route::get('/{note}', [StandaloneNoteController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '/{note}', [StandaloneNoteController::class, 'update'])->name('update');
    Route::delete('/{note}', [StandaloneNoteController::class, 'destroy'])->name('destroy');
    Route::post('/{note}/media', [StandaloneNoteController::class, 'storeMedia'])->name('media.store');
});
