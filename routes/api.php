<?php
use App\Http\Controllers\PostController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('posts', PostController::class);
});
