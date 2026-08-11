<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Utils\Distance;
use App\Utils\Image;
use App\Utils\Notification;
use App\Utils\Websocket;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;


class OrderController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;
    
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum')
        ];
    }

    public function placeOrder(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'type' => 'required|in:delivery,pickup',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'proof_of_payment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'],
            'reference_id' => 'nullable|string|max:255',
        ]);
    
        $cartItems = CartItem::with('food')->where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        DB::beginTransaction();
        try {
            $total = $cartItems->sum(fn($item) => ($item->food->price * $item->quantity));

            $validated['user_id'] = $user->id;
            $validated['total_price'] = $total;
            $validated['status'] = 'pending';

            if ($request->hasFile('proof_of_payment')) {
                $file = $request->file('proof_of_payment');
                $validated['proof_of_payment'] = Image::uploadImage($file, 'proof');
            }

            $order = Order::create($validated);

            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'food_id'  => $item->food_id,
                    'quantity' => $item->quantity,
                    'price'    => $item->food->price,
                ]);
            }

            CartItem::where('user_id', $user->id)->delete();
            DB::commit();

            $dis = Distance::getDistance($order->latitude, $order->longitude);

            if ($order->type == "pickup") {
                $dis_price = 0;
            } else {
                $base_km = 3;
                $base_price = 55;
                $extra_price = 10;

                if ($dis <= $base_km) {
                    $dis_price = $base_price;
                } else {
                    $extra_km = ceil($dis - $base_km);
                    $dis_price = $base_price + ($extra_km * $extra_price);
                }
            }

            $order->total_price = $order->total_price + $dis_price;
            $order->save();
            
            Websocket::broadcast('order', 'create', $order->load('items.food', 'items.food.categories', 'user'), $order->user->id);
            Notification::notify('order', 'create', $order->user->id, $order);

            return response()->json(['message' => 'Order Placed']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to place order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function getUserOrder(Request $request)
    {
        $user = $request->user();

        $orders = $user->Orders()->with('items.food', 'items.food.categories', 'user')->orderBy('created_at', 'desc')->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders found'], 404);
        }

        return response()->json([
            'orders' => $orders
        ], 200);
    }

    public function cancelOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $order = $user->Orders()->where('id', $orderId)->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order cannot be cancelled'], 400);
        }

        $order->status = 'cancelled';
        $order->save();

        Http::post(config('services.websocket.http_url') . "/broadcast/order", [
            'event' => 'cancelled',
            'order' => $order->load('items'),
        ]);
        Websocket::broadcast('order', 'cancelled', $order->load('items'));
        Notification::notify('order', 'cancelled', $order->user->id, $order);

        return response()->json(['message' => 'Order cancelled successfully', 'order' => $order], 200);
    }

    public function updateOrderStatus(Request $request, $orderId)
    {
        $this->authorize('isAdmin', Order::class);

        $request->validate([
            'status' => 'required|in:pending,approved,declined,completed'
        ]);

        $order = Order::find($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->status = $request->status;
        $order->save();

        Http::post(config('services.websocket.http_url') . "/broadcast/order", [
            'event' => 'update',
            'user_id' => $order->user->id,
            'order' => $order->load('items.food', 'items.food.categories'),
        ]);
        Notification::notify('order', $order->status, $order->user->id, $order);

        return response()->json(['message' => 'Order status updated', 'order' => $order], 200);
    }

    public function updateOrderETC(Request $request, $orderId)
    {
        $this->authorize('isAdmin', Order::class);

        $request->validate([
            'etc' => 'required|numeric'
        ]);

        $order = Order::find($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->estimated_time_of_completion = $request->etc;
        $order->save();

        Http::post(config('services.websocket.http_url') . "/broadcast/order", [
            'event' => 'update',
            'user_id' => $order->user->id,
            'order' => $order->load('items.food', 'items.food.categories'),
        ]);
        Notification::notify('order', 'update', $order->user->id, $order);

        return response()->json(['message' => 'Order status updated', 'order' => $order], 200);
    }

    public function allOrders(Request $request)
    {
        $this->authorize('isAdmin', Order::class);

        $user = $request->user();
        if (!$user || $user->role !== "admin") {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $perPage = $request->get('per_page', 10);
        $status = $request->get('status');
        $category = $request->get('category');

        $query = Order::with(['items.food.categories', 'user']);

        if ($status && $status !== "all") {
            $query->where("status", $status);
        }

        if ($category && $category !== "all") {
            $query->whereHas("items.food.categories", function ($q) use ($category) {
                $q->where("name", "LIKE", "%{$category}%");
            });
        }

        $orders = $query->orderBy("created_at", "desc")->paginate($perPage);

        return response()->json([
            'orders' => $orders->items(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
            'per_page' => $orders->perPage(),
        ]);
    }
}