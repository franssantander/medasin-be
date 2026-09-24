<?php

use App\Http\Controllers\Notification\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->name('notifications.')->middleware('auth:api')->controller(NotificationController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::patch('/{notification}/read', 'markRead')->name('read');
});
