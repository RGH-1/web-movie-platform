<?php

use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get(
    '/auth/{provider}/redirect',
    [OAuthController::class, 'redirect']
)->middleware('throttle:10,1')->name('oauth.redirect');

Route::get(
    '/auth/{provider}/callback',
    [OAuthController::class, 'callback']
)->middleware('throttle:10,1')->name('oauth.callback');
