<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartItemController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\VerificationChannelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Role boundaries (PRD §7, FR-01.12)
|--------------------------------------------------------------------------
| Business labels: super_admin = Store Manager, admin = Store Agent.
|
| The `role` middleware is an EXACT allowlist with no hierarchy, which is what
| lets the two cross-cutting exclusions below be expressed at all:
|
|   FR-07.6  Catalogue management (add/edit/price/archive items and
|            categories) is restricted to the Store Manager - the Store Agent
|            is fenced out.
|   FR-07.2  The Store Agent adjusts stock and availability "without catalogue
|            editing rights".
|   BR-29    ...and the Store Manager is explicitly excluded from that stock
|            work - fenced out, not merely lower priority.
|
| The two staff tiers are therefore non-overlapping in both directions, not a
| ladder. Nothing here consults the React route guards; those gate on a value
| the client itself holds and are UX only.
*/

// ---------------------------------------------------------------------------
// Public - no token required
// ---------------------------------------------------------------------------
Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:register', 'throttle:otp-send']);

// Blocking phone verification. Both are unauthenticated by necessity: the
// account exists but holds no token until the code is confirmed.
Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/otp/resend', [OtpController::class, 'resend'])->middleware('throttle:otp-send');
Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:login');

// Answering the 2FA challenge completes a login, so it cannot require a token.
// The challenge_token issued by /login stands in for the password check.
Route::post('/2fa/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:otp-verify');

// Which channels can actually deliver. Public: registration reads it before
// any account exists.
Route::get('/verification/channels', [VerificationChannelController::class, 'index']);

// Menu browsing is open to guests (PRD §5: Guest browses and orders).
Route::get('/foods', [FoodController::class, 'index']);
Route::get('/foods/{food}', [FoodController::class, 'show']);
Route::get('/drinks', [FoodController::class, 'drinks']);
Route::get('/sides', [FoodController::class, 'sides']);
Route::get('/category', [CategoryController::class, 'index']);
Route::get('/category/{category}', [CategoryController::class, 'show']);

// ---------------------------------------------------------------------------
// Authenticated - any role
// ---------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'userDetails']);
    Route::put('/user/update', [AuthController::class, 'updateUser'])->middleware('throttle:user-update');
    Route::post('/logout', [AuthController::class, 'logout']);

    // Two-factor enrolment. The channel is fixed once, here.
    Route::post('/2fa/enable', [TwoFactorController::class, 'enable'])->middleware('throttle:otp-send');
    Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:otp-verify');

    // BR-33: password changes are re-verified with an OTP on a channel chosen
    // fresh each attempt. Deliberately not part of /user/update.
    Route::post('/user/password/request-code', [PasswordChangeController::class, 'requestCode'])->middleware('throttle:otp-send');
    Route::post('/user/password', [PasswordChangeController::class, 'update'])->middleware('throttle:otp-verify');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    Route::get('/cart', [CartItemController::class, 'userCart']);
    Route::post('/cart/add/{foodId}', [CartItemController::class, 'addToCart']);
    Route::delete('/cart/remove/{orderId}', [CartItemController::class, 'removeToCart']);

    // A customer's own orders. Ownership is enforced inside the controller;
    // the route group only establishes that a token is present.
    Route::post('/order/place', [OrderController::class, 'placeOrder'])->middleware('throttle:place-order');
    Route::get('/orders', [OrderController::class, 'getUserOrder']);
    Route::post('/order/{id}/cancel', [OrderController::class, 'cancelOrder']);
});

// ---------------------------------------------------------------------------
// Staff - Store Agent AND Store Manager
// ---------------------------------------------------------------------------
// Order fulfilment (FR-07.1) plus the Manager's reporting view over the same
// data. The PRD assigns fulfilment to the Store Agent but does not fence the
// Manager out of it the way BR-29/FR-07.6 fence the two catalogue/stock
// boundaries, so this stays shared.
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->group(function () {
    Route::get('/orders/all', [OrderController::class, 'allOrders']);
    Route::put('/order/{id}/status', [OrderController::class, 'updateOrderStatus']);
    Route::put('/order/{id}/etc', [OrderController::class, 'updateOrderETC']);
});

// ---------------------------------------------------------------------------
// Store Agent ONLY - the Store Manager is fenced out (BR-29, FR-07.2)
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::patch('/foods/{food}/stock', [FoodController::class, 'updateStock']);
    Route::patch('/foods/{food}/availability', [FoodController::class, 'updateAvailability']);
});

// ---------------------------------------------------------------------------
// Store Manager ONLY - the Store Agent is fenced out (FR-07.6)
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    // Catalogue: items.
    Route::post('/foods', [FoodController::class, 'store']);
    Route::put('/foods/{food}', [FoodController::class, 'update']);
    Route::patch('/foods/{food}', [FoodController::class, 'update']);
    Route::delete('/foods/{food}', [FoodController::class, 'destroy']);

    // Catalogue: categories.
    Route::post('/category', [CategoryController::class, 'store']);
    Route::put('/category/{category}', [CategoryController::class, 'update']);
    Route::patch('/category/{category}', [CategoryController::class, 'update']);
    Route::delete('/category/{category}', [CategoryController::class, 'destroy']);

    // User and role administration (PRD §5: the Manager governs user accounts).
    Route::patch('/admin/users/{user}', [AuthController::class, 'updateUserRole'])->middleware('throttle:user-update');
});
