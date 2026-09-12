<?php

use App\Http\Controllers\Letter\LetterController;
use App\Http\Controllers\Letter\LetterExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('letters')->name('letters.')->middleware('auth:api')->group(function () {
    Route::get('/', [LetterController::class, 'index'])->name('index');
    Route::post('/', [LetterController::class, 'store'])->name('store');
    Route::get('/{letter}', [LetterController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '/{letter}', [LetterController::class, 'update'])->name('update');
    Route::delete('/{letter}', [LetterController::class, 'destroy'])->name('destroy');
});

Route::prefix('letters/{letter}/exports')
    ->name('letters.exports.')
    ->middleware('auth:api')
    ->scopeBindings()
    ->group(function () {
        Route::get('/', [LetterExportController::class, 'index'])->name('index');
        Route::post('/', [LetterExportController::class, 'store'])->name('store');
        Route::get('/{letterExport}', [LetterExportController::class, 'show'])->name('show');
        Route::patch('/{letterExport}', [LetterExportController::class, 'update'])->name('update');
    });
