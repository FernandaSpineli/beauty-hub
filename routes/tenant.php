<?php

declare(strict_types=1);

use App\Http\Controllers\Store\ShowStore;
use Illuminate\Support\Facades\Route;

Route::get('store', ShowStore::class);
