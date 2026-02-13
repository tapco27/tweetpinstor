<?php

use Illuminate\Support\Facades\Route;

/**
 * Web routes only (no /v1 API here).
 * Keep this file for web pages if you add them later.
 */
Route::get('/', function () {
    return response()->json(['ok' => true, 'service' => 'tweetpin-store']);
});
