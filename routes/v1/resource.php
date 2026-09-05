<?php

use App\Http\Controllers\Resource\ResourceController;
use Illuminate\Support\Facades\Route;

Route::get('resource', [ResourceController::class, 'index'])
    ->middleware('auth:api')
    ->name('resource.index');

Route::middleware('auth:api')->group(function () {
    Route::post('resource', [ResourceController::class, 'store'])->name('resource.store');
    Route::get('resource/tags', [ResourceController::class, 'tags'])->name('resource.tags');
    Route::get('resource/{resource:uuid}', [ResourceController::class, 'show'])->name('resource.show');
    Route::patch('resource/{resource:uuid}', [ResourceController::class, 'update'])->name('resource.update');
    Route::post('resource/{resource:uuid}/attachments', [ResourceController::class, 'storeAttachment'])->name('resource.attachments.store');
    Route::delete('resource/{resource:uuid}/attachments/{attachment}', [ResourceController::class, 'destroyAttachment'])->name('resource.attachments.destroy');
    Route::post('resource/{resource:uuid}/archive', [ResourceController::class, 'archive'])->name('resource.archive');
    Route::post('resource/{resource:uuid}/restore', [ResourceController::class, 'restore'])->name('resource.restore');
    Route::get('resource/{resource:uuid}/attachments/{attachment}', [ResourceController::class, 'attachment'])->name('resource.attachments.show');
});
