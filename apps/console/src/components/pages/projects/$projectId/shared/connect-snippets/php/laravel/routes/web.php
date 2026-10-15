<?php

use App\Http\Controllers\PolicyController;
use Illuminate\Support\Facades\Route;

Route::patch('/v1/policies', [PolicyController::class, 'update']);
Route::get('/v1/policies', [PolicyController::class, 'index']);
