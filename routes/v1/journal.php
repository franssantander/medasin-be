<?php

use App\Http\Controllers\Journal\JournalController;
use Illuminate\Support\Facades\Route;

Route::prefix('journal')->name('journal.')->middleware('auth:api')->group(function () {
    Route::get('/', [JournalController::class, 'index'])->name('index');
    Route::post('/', [JournalController::class, 'store'])->name('store');
    Route::get('/{journalEntry}', [JournalController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '/{journalEntry}', [JournalController::class, 'update'])->name('update');
    Route::delete('/{journalEntry}', [JournalController::class, 'destroy'])->name('destroy');
});
