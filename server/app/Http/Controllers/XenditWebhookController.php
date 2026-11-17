<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Xendit's payment notifications (FR-04.3).
 *
 * Authenticated by the verification token Xendit sends in x-callback-token.
 * The body is used only to find which of our payments it is about: what the
 * payment actually did is then read back from Xendit (PaymentSettlement), so
 * even a forged notification with a stolen token cannot mark anything paid.
 *
 * Point both "Payment Session" and "Payments" webhooks in the Xendit dashboard
 * here: payment_session.completed / .expired, and payment.capture.
 */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentSettlement $settlement)
    {
        $expected = (string) config('services.xendit.webhook_token');
        $given = (string) $request->header('x-callback-token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid callback token.'], 401);
        }

        $event = (string) $request->input('event', '');
        $data = (array) $request->input('data', []);
        $payment = $this->paymentFor($event, $data);

        if ($payment === null) {
            // Not one of ours, or not an event we act on. Acknowledged so Xendit
            // does not retry it for a day.
            return response()->json(['received' => true]);
        }

        try {
            $settlement->reconcile($payment);
        } catch (PaymentGatewayException $e) {
            Log::warning('A payment notification could not be checked with Xendit; it will be retried.', [
                'payment_id' => $payment->getKey(),
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            // Not 2xx, so Xendit tries again later (up to six times over a day).
            return response()->json(['message' => 'Try again later.'], 503);
        }

        return response()->json(['received' => true]);
    }

    private function paymentFor(string $event, array $data): ?Payment
    {
        if (str_starts_with($event, 'payment_session.')) {
            $sessionId = (string) ($data['payment_session_id'] ?? '');

            return ($sessionId === '' ? null : Payment::query()->where('provider_reference_id', $sessionId)->first())
                ?? Payment::forGatewayReference($data['reference_id'] ?? null);
        }

        if (str_starts_with($event, 'payment.')) {
            return Payment::forGatewayReference($data['reference_id'] ?? null);
        }

        return null;
    }
}
