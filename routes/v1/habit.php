<?php

use App\Http\Controllers\Habit\HabitController;
use Illuminate\Support\Facades\Route;

Route::prefix('habits')->name('habits.')->middleware('auth:api')->controller(HabitController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::get('/calendar', 'calendar')->name('calendar');
    Route::get('/{habit}', 'show')->name('show');
    Route::put('/{habit}', 'update')->name('update');
    Route::patch('/{habit}', 'update');
    Route::delete('/{habit}', 'destroy')->name('destroy');
    Route::put('/{habit}/check-ins/{date}', 'checkIn')->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('check-ins.update');
});
