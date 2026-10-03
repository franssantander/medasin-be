<?php

use App\Http\Controllers\Profile\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function (): void {
    Route::post('image', 'storeImage')->name('image.store');
    Route::delete('image', 'removeImage')->name('image.destroy');
    Route::patch('password', 'changePassword')->name('password.update');
    Route::delete('/', 'destroy')->name('destroy');
});
