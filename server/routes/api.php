<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DeviceSessionController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PosterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecaptchaConfigController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\VerificationChannelController;
use App\Services\Recaptcha\RecaptchaAction;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:register', 'throttle:otp-send', 'recaptcha:'.RecaptchaAction::REGISTER]);

Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware(['throttle:otp-verify', 'recaptcha:'.RecaptchaAction::OTP_VERIFY]);
Route::post('/otp/resend', [OtpController::class, 'resend'])->middleware(['throttle:otp-send', 'recaptcha:'.RecaptchaAction::OTP_RESEND]);
Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware(['throttle:login', 'recaptcha:'.RecaptchaAction::LOGIN]);
Route::post('/password/forgot', [PasswordResetController::class, 'requestCode'])->middleware(['throttle:password-reset', 'recaptcha:'.RecaptchaAction::PASSWORD_FORGOT]);
Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware(['throttle:otp-verify', 'recaptcha:'.RecaptchaAction::PASSWORD_RESET]);
Route::post('/2fa/challenge', [TwoFactorController::class, 'challenge'])->middleware(['throttle:otp-verify', 'recaptcha:'.RecaptchaAction::TWO_FACTOR_CHALLENGE]);
Route::get('/verification/channels', [VerificationChannelController::class, 'index']);
Route::get('/config/recaptcha', [RecaptchaConfigController::class, 'show']);

// Menu browsing
Route::get('/foods', [FoodController::class, 'index']);
Route::get('/foods/{food}', [FoodController::class, 'show']);
Route::get('/drinks', [FoodController::class, 'drinks']);
Route::get('/sides', [FoodController::class, 'sides']);
Route::get('/category', [CategoryController::class, 'index']);
Route::get('/category/{category}', [CategoryController::class, 'show']);
Route::get('/posters', [PosterController::class, 'index']);

// Customer
Route::middleware(['auth:sanctum', 'device-check'])->group(function () {
    Route::get('/user', [AuthController::class, 'userDetails']);
    Route::put('/user/update', [AuthController::class, 'updateUser'])->middleware('throttle:user-update');
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/2fa/enable', [TwoFactorController::class, 'enable'])->middleware(['throttle:otp-send', 'recaptcha:'.RecaptchaAction::TWO_FACTOR_ENABLE]);
    Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:otp-verify');

    Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:two-factor-disable');

    Route::get('/user/devices', [DeviceSessionController::class, 'index']);
    Route::post('/user/devices/sign-out-others', [DeviceSessionController::class, 'signOutOthers'])
        ->middleware('throttle:user-update');
    Route::patch('/user/devices/{device}/trust', [DeviceSessionController::class, 'trust'])
        ->middleware('throttle:device-trust');
    Route::delete('/user/devices/{device}', [DeviceSessionController::class, 'destroy'])
        ->middleware('throttle:user-update');

    Route::post('/user/password/request-code', [PasswordChangeController::class, 'requestCode'])->middleware(['throttle:otp-send', 'recaptcha:'.RecaptchaAction::PASSWORD_CHANGE]);
    Route::post('/user/password', [PasswordChangeController::class, 'update'])->middleware(['throttle:otp-verify', 'recaptcha:'.RecaptchaAction::PASSWORD_CHANGE]);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:user-update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:user-update');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->middleware('throttle:user-update');

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store'])->middleware('throttle:user-update');
    Route::put('/addresses/{address}', [AddressController::class, 'update'])->middleware('throttle:user-update');
    Route::patch('/addresses/{address}/default', [AddressController::class, 'setDefault'])->middleware('throttle:user-update');
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->middleware('throttle:user-update');

    Route::get('/discount', [DiscountController::class, 'show']);
    Route::post('/discount', [DiscountController::class, 'store'])->middleware('throttle:discount-apply');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/items', [CartController::class, 'store']);
    Route::patch('/cart/items/{cartItem}', [CartController::class, 'updateQuantity']);
    Route::delete('/cart/items', [CartController::class, 'destroyMany']);
    Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy']);
    Route::delete('/cart', [CartController::class, 'clear']);

    Route::post('/order/place', [OrderController::class, 'placeOrder'])->middleware('throttle:place-order');
    Route::get('/orders', [OrderController::class, 'getUserOrder']);
    Route::post('/order/{id}/cancel', [OrderController::class, 'cancelOrder']);
});

// Superadmin and Admin
Route::middleware(['auth:sanctum', 'device-check', 'role:admin,super_admin'])->group(function () {
    Route::get('/admin/discount-claims', [DiscountController::class, 'index']);
    Route::post('/admin/discount-claims/{discount}/approve', [DiscountController::class, 'approve']);
    Route::post('/admin/discount-claims/{discount}/reject', [DiscountController::class, 'reject']);

    Route::get('/orders/all', [OrderController::class, 'allOrders']);
    Route::put('/order/{id}/status', [OrderController::class, 'updateOrderStatus']);
    Route::put('/order/{id}/etc', [OrderController::class, 'updateOrderETC']);
});

// Store Agent
Route::middleware(['auth:sanctum', 'device-check', 'role:admin'])->group(function () {
    Route::patch('/foods/{food}/stock', [FoodController::class, 'updateStock']);
    Route::patch('/foods/{food}/availability', [FoodController::class, 'updateAvailability']);
});

// Store Manager
Route::middleware(['auth:sanctum', 'device-check', 'role:super_admin'])->group(function () {
    Route::post('/foods', [FoodController::class, 'store']);
    Route::put('/foods/{food}', [FoodController::class, 'update']);
    Route::patch('/foods/{food}', [FoodController::class, 'update']);
    Route::delete('/foods/{food}', [FoodController::class, 'destroy']);

    Route::post('/category', [CategoryController::class, 'store']);
    Route::put('/category/{category}', [CategoryController::class, 'update']);
    Route::patch('/category/{category}', [CategoryController::class, 'update']);
    Route::delete('/category/{category}', [CategoryController::class, 'destroy']);

    Route::get('/admin/posters', [PosterController::class, 'all']);
    Route::post('/admin/posters', [PosterController::class, 'store']);
    Route::post('/admin/posters/{poster}', [PosterController::class, 'update']);
    Route::delete('/admin/posters/{poster}', [PosterController::class, 'destroy']);

    Route::patch('/admin/users/{user}', [AuthController::class, 'updateUserRole'])->middleware('throttle:user-update');

    Route::get('/admin/settings/discount', [SystemSettingController::class, 'showDiscount']);
    Route::put('/admin/settings/discount', [SystemSettingController::class, 'updateDiscount'])
        ->middleware('throttle:user-update');
});
