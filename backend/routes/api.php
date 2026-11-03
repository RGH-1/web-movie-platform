<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TmdbIntegrationController;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

//temporary endpoint for the TMDB integration test component
Route::get('/integration/tmdb/search',[
    TmdbIntegrationController::class,
    'search',
]);
