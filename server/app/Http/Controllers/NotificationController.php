<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use AuthorizesRequests;

    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum')
        ];
    }

    /**
     * Get all notifications of authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifications,
        ]);
    }

    /**
     * Show single notification (with ownership check)
     */
    public function show(Request $request, Notification $notification)
    {
        $user = $request->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access to this notification.'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $notification,
        ]);
    }

    /**
     * ✅ Mark notification as READ
     */
    public function markAsRead(Request $request, Notification $notification)
    {
        $user = $request->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized.'
            ], 403);
        }

        $notification->update([
            'is_read' => true,
            // OR use:
            // 'read_at' => now()
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marked as read.',
            'data' => $notification
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        $marked = $request->user()->notifications()
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Notifications marked as read.',
            'marked' => $marked,
        ]);
    }

    /**
     * ✅ Delete notification
     */
    public function destroy(Request $request, Notification $notification)
    {
        $user = $request->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized.'
            ], 403);
        }

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification deleted successfully.'
        ]);
    }
}