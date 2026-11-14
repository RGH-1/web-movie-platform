<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GithubAuthController;

// Web middleware supplies the session needed for one-time OAuth state validation.
Route::get('/auth/github/redirect', [GithubAuthController::class, 'redirect'])->middleware('throttle:30,1');
Route::get('/auth/github/callback', [GithubAuthController::class, 'callback'])->middleware('throttle:30,1');

Route::get('/', function () {
    return view('welcome');
});
