<?php

namespace App\Utils; 

use App\Models\PaymongoSession;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;



class GcashCheckout
{
    public static function createCheckout($order)
        {
        
            // Amount in centavos
            $amount = (int) round($order->total_price * 100);

            $client = new Client([
                'base_uri' => 'https://api.paymongo.com/v1/',
                'auth' => [env('PAYMONGO_SECRET'), ''], // HTTP Basic: secret key as username
                'verify' => false 
            ]);

            $payload = [
                'data' => [
                    'attributes' => [
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'description' => "Order #{$order->id}",
                        // include order_id so success_url returns it to frontend:
                        'success_url' => env('FRONTEND_URL') . "/checkout/success/{$order->id}",
                        'cancel_url'  => env('FRONTEND_URL') . "/checkout/cancel/{$order->id}",
                        // restrict to GCash so the Checkout page shows GCash:
                        'payment_method_types' => ['gcash'],
                        'line_items' => [
                            [
                                'name' => "Order #{$order->id}",
                                'quantity' => 1,
                                'amount' => $amount,
                                'currency' => 'PHP'
                            ]
                        ]
                    ]
                ]
            ];

            try {
                $res = $client->post('checkout_sessions', ['json' => $payload]);
                $body = json_decode((string)$res->getBody(), true);

                $checkoutId = $body['data']['id'] ?? null;
                $checkoutUrl = $body['data']['attributes']['checkout_url'] ?? null;

                // Save mapping for later verification
                PaymongoSession::create(['checkout_id' => $checkoutId, 'order_id' => $order->id]);

                return response()->json([
                    'checkout_url' => $checkoutUrl,
                    'checkout_id' => $checkoutId
                ]);
            } catch (\Exception $e) {
                Log::error('Paymongo create checkout error: '.$e->getMessage());
                return response()->json(['error' => 'Unable to initialize payment'], 500);
            }
        }
}