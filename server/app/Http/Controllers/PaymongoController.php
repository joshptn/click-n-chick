<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymongoSession;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PaymongoController extends Controller
{
        public function verifyByOrder($orderId)
    {
        $session = PaymongoSession::where('order_id', $orderId)->latest()->first();
        if (! $session) {
            return response()->json(['status' => 'no_session'], 404);
        }

        $client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.paymongo.com/v1/',
            'auth' => [env('PAYMONGO_SECRET'), ''],
            'verify' => false // ⚠️ only for local dev, remove in production
        ]);

        try {
            $res = $client->get("checkout_sessions/{$session->checkout_id}");
            $body = json_decode((string)$res->getBody(), true);

            $checkout = data_get($body, 'data.attributes', []);
            $referenceNumber = data_get($checkout, 'reference_number');
            $payments = data_get($checkout, 'payments', []);
            $piStatus = data_get($checkout, 'payment_intent.attributes.status');

            $paid = false;
            $gcashRef = null;

            foreach ($payments as $p) {
                $status = data_get($p, 'attributes.status') ?? data_get($p, 'status');

                $methodType = data_get($p, 'attributes.source.attributes.type');
                if ($methodType === 'gcash') {
                    $gcashRef = data_get($p, 'attributes.source.attributes.details.reference_number');
                }

                if ($status === 'paid' || $status === 'succeeded') {
                    $paid = true;
                }
            }

            if (! $paid && $piStatus && in_array($piStatus, ['succeeded', 'paid'])) {
                $paid = true;
            }

            $order = Order::find($orderId);

            if ($paid) {
                if ($order && ! $order->paid_at) {
                    $order->payment_status = 'paid';
                    $order->paid_at = now();
                    $order->save();
                }

                return response()->json([
                    'status' => 'paid',
                    'reference_number' => $referenceNumber,
                    'gcash_reference_number' => $gcashRef,
                    'checkout_details' => $checkout,
                ]);
            } else {
                // ❌ Delete unpaid/unsuccessful order
                if ($order) {
                    $order->delete();
                    Http::post(config('services.websocket.http_url') ."/broadcast/food", [
                        "event" => "delete",
                        "order"  => $order
                    ]);
                }

                return response()->json([
                    'status' => 'unpaid',
                    'message' => 'Order was deleted because payment was unsuccessful',
                    'reference_number' => $referenceNumber,
                    'gcash_reference_number' => $gcashRef,
                    'checkout_details' => $checkout,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Paymongo verify error: '.$e->getMessage());
            return response()->json(['error' => 'Could not verify payment'], 500);
        }
    }

}
