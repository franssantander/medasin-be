<?php

use App\Http\Controllers\Calendar\CalendarPlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('calendar/plans')->name('calendar.plans.')->middleware('auth:api')->controller(CalendarPlanController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/upcoming', 'upcoming')->name('upcoming');
    Route::post('/', 'store')->name('store');
    Route::get('/{calendarPlan}', 'show')->name('show');
    Route::put('/{calendarPlan}', 'update')->name('update');
    Route::delete('/{calendarPlan}', 'destroy')->name('destroy');
});
