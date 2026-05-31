<?php

use Illuminate\Support\Facades\Route;
use NoSocket\Laravel\Http\PollController;
use NoSocket\Laravel\Http\TokenController;

Route::post('/nosocket/poll', PollController::class)->name('nosocket.poll');
Route::post('/nosocket/token', TokenController::class)->middleware(['web', 'auth'])->name('nosocket.token');
