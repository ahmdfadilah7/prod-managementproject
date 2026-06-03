<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $spa = public_path('index.html');
    if (file_exists($spa)) {
        return response()->file($spa);
    }

    return view('welcome');
});

/** SPA Vue (history mode) — production setelah frontend/dist disalin ke public */
Route::fallback(function () {
    if (request()->is('api/*')) {
        abort(404);
    }

    $spa = public_path('index.html');
    if (file_exists($spa)) {
        return response()->file($spa);
    }

    abort(404);
});
