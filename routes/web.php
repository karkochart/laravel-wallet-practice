<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/leak/{name}', \App\Http\Controllers\LeakyController::class);
