<?php

use App\Http\Controllers\Trash\TrashController;
use Illuminate\Support\Facades\Route;

Route::prefix('trash')->name('trash.')->middleware('auth:api')->controller(TrashController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{trashEntry}/restore', 'restore')->name('restore');
    Route::delete('/{trashEntry}', 'destroy')->name('destroy');
});
