<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartItemController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register',[AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login',[AuthController::class, 'login'])->name('login')->middleware('throttle:login');
Route::get('/user',[AuthController::class, 'userDetails'])->middleware('auth:sanctum');
Route::put('/user/update',[AuthController::class, 'updateUser'])->middleware(['auth:sanctum', 'throttle:user-update']);

Route::patch('/admin/users/{user}', [AuthController::class, 'updateUserRole'])
    ->middleware(['auth:sanctum', 'can:isSuperAdmin,App\Models\User', 'throttle:user-update']);
Route::post('/logout',[AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/notifications', [NotificationController::class, 'index'])->middleware('auth:sanctum');
Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->middleware('auth:sanctum');
// Mark as read
Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->middleware('auth:sanctum');

// Delete notification
Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->middleware('auth:sanctum');

Route::get('/cart', [CartItemController::class, 'userCart']);
Route::post('/cart/add/{foodId}', [CartItemController::class, 'addToCart']);
Route::delete('/cart/remove/{orderId}', [CartItemController::class, 'removeToCart']);

Route::post('/order/place', [OrderController::class, 'placeOrder'])->middleware('throttle:place-order');
Route::get('/orders', [OrderController::class, 'getUserOrder']);
Route::get('/orders/all', [OrderController::class, 'allOrders']);
Route::post('/order/{id}/cancel', [OrderController::class, 'cancelOrder']);
Route::put('/order/{id}/status', [OrderController::class, 'updateOrderStatus']);
Route::put('/order/{id}/etc', [OrderController::class, 'updateOrderETC']);
// Route::delete('/order/{order}/delete', [OrderController::class, 'deleteOrder']);

Route::apiResource('foods', FoodController::class);
Route::apiResource('category', CategoryController::class);
Route::get('drinks', [FoodController::class, 'drinks']);
Route::get('sides', [FoodController::class, 'sides']);