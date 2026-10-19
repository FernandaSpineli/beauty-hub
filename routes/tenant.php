<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ChangePassword;
use App\Http\Controllers\Account\ShowAccount;
use App\Http\Controllers\Account\UpdateAccount;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\KitController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\VariantController;
use App\Http\Controllers\Auth\ForgotPassword;
use App\Http\Controllers\Auth\Login;
use App\Http\Controllers\Auth\Logout;
use App\Http\Controllers\Auth\Register;
use App\Http\Controllers\Auth\ResetPassword;
use App\Http\Controllers\Cart\CartController;
use App\Http\Controllers\Catalog\ListBrands;
use App\Http\Controllers\Catalog\ListCategories;
use App\Http\Controllers\Catalog\ListProducts;
use App\Http\Controllers\Catalog\ShowProduct;
use App\Http\Controllers\Store\ShowStore;
use App\Http\Middleware\EnsureStoreManager;
use Illuminate\Support\Facades\Route;

Route::get('store', ShowStore::class);

Route::get('categories', ListCategories::class);
Route::get('brands', ListBrands::class);
Route::get('products', ListProducts::class);
Route::get('products/{slug}', ShowProduct::class)->where('slug', '[a-z0-9-]+');

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

    Route::get('cart', [CartController::class, 'show']);
    Route::delete('cart', [CartController::class, 'clear']);
    Route::post('cart/items', [CartController::class, 'addItem']);
    Route::patch('cart/items/{variantId}', [CartController::class, 'updateItem'])->whereUuid('variantId');
    Route::delete('cart/items/{variantId}', [CartController::class, 'removeItem'])->whereUuid('variantId');
    Route::put('cart/coupon', [CartController::class, 'applyCoupon']);
    Route::delete('cart/coupon', [CartController::class, 'removeCoupon']);
});

// Store management: owner and staff only.
Route::prefix('admin')->middleware(['auth:sanctum', EnsureStoreManager::class])->group(function (): void {
    Route::apiResource('brands', BrandController::class)->except('show')->whereUuid('brand');
    Route::apiResource('categories', CategoryController::class)->except('show')->whereUuid('category');
    Route::apiResource('products', ProductController::class)->except('destroy')->whereUuid('product');
    Route::post('products/{product}/variants', [VariantController::class, 'store'])->whereUuid('product');
    Route::patch('variants/{variant}', [VariantController::class, 'update'])->whereUuid('variant');
    Route::delete('variants/{variant}', [VariantController::class, 'destroy'])->whereUuid('variant');

    Route::get('variants/{variant}/stock', [InventoryController::class, 'show'])->whereUuid('variant');
    Route::post('variants/{variant}/lots', [InventoryController::class, 'receive'])->whereUuid('variant');
    Route::post('lots/{lot}/adjustments', [InventoryController::class, 'adjust'])->whereUuid('lot');
    Route::get('stock/expiring', [InventoryController::class, 'expiring']);
    Route::put('variants/{variant}/components', [KitController::class, 'update'])->whereUuid('variant');

    Route::apiResource('coupons', CouponController::class)->except('show')->whereUuid('coupon');
});
