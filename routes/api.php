<?php

use App\Http\Controllers\LikeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('likes', LikeController::class);
});
