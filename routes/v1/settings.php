<?php

use App\Http\Controllers\Settings\PreferencesController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function (): void {
    Route::patch('preferences', [PreferencesController::class, 'update'])->name('preferences.update');
});
