<?php

use App\Http\Controllers\Plan\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
