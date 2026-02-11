<?php

use App\Http\Controllers\RedirectController;
use App\Http\Controllers\ResolveController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', static fn () => response()->noContent());

Route::get('/_internal/resolve/{code}', ResolveController::class)
    ->where('code', '[0-9A-Za-z]{1,8}');

Route::get('/{code}', RedirectController::class)
    ->where('code', '[0-9A-Za-z]{1,8}');
