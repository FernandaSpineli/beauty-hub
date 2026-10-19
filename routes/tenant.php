<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ChangePassword;
use App\Http\Controllers\Account\ShowAccount;
use App\Http\Controllers\Account\UpdateAccount;
use App\Http\Controllers\Auth\ForgotPassword;
use App\Http\Controllers\Auth\Login;
use App\Http\Controllers\Auth\Logout;
use App\Http\Controllers\Auth\Register;
use App\Http\Controllers\Auth\ResetPassword;
use App\Http\Controllers\Store\ShowStore;
use Illuminate\Support\Facades\Route;

Route::get('store', ShowStore::class);

Route::prefix('auth')->group(function (): void {
    Route::post('register', Register::class)->middleware('throttle:auth');
    Route::post('login', Login::class)->middleware('throttle:login');
    Route::post('password/forgot', ForgotPassword::class)->middleware('throttle:auth');
    Route::post('password/reset', ResetPassword::class)->middleware('throttle:auth');
    Route::post('logout', Logout::class)->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', ShowAccount::class);
    Route::patch('me', UpdateAccount::class);
    Route::put('me/password', ChangePassword::class);
});
