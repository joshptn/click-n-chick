<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentRefunds;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Refunds from Order Records (decided 2026-11-23, BR-21d).
 *
 *   complete  a Store Agent ticks a refund by hand as refunded, once they
 *             have sent the money; optionally with a reference number
 *   payer     who paid, as Xendit reports it, for finding the payment or
 *             refunding by hand; read from Xendit each time, never stored
 */
class PaymentRefundController extends Controller
{
    use AuthorizesRequests;

    /** POST /api/agent/payments/{payment}/refunded */
    public function complete(Request $request, Payment $payment, PaymentRefunds $refunds)
    {
        $this->authorize('refund', $payment);

        $validated = $request->validate([
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if ($payment->refund_status === Payment::REFUND_REFUNDED) {
            return response()->json([
                'message' => 'This refund is already marked as refunded.',
                'error_code' => 'ALREADY_REFUNDED',
                'refund' => $payment->refundSummary(),
            ], 409);
        }

        if (! $payment->owesRefund() || $payment->refund_status !== Payment::REFUND_MANUAL) {
            return response()->json([
                'message' => $payment->refund_status === Payment::REFUND_PENDING
                    ? 'This refund is going back to GCash through Xendit, so there is nothing to refund by hand.'
                    : 'There is no refund by hand on this payment.',
                'error_code' => 'NOT_A_REFUND_BY_HAND',
                'refund' => $payment->refundSummary(),
            ], 422);
        }

        $refunds->completed($payment, $validated['reference'] ?? null);

        return response()->json([
            'message' => 'Marked as refunded. The customer has been told.',
            'refund' => $payment->refresh()->refundSummary(),
        ]);
    }

    /** GET /api/agent/payments/{payment}/payer */
    public function payer(Payment $payment, PaymentGateway $gateway)
    {
        $this->authorize('view', $payment);

        $paymentId = (string) $payment->idempotency_key;

        if (! $payment->isVerified() || ! str_starts_with($paymentId, 'py-')) {
            return response()->json(['payer' => null]);
        }

        try {
            return response()->json(['payer' => $gateway->payerDetails($paymentId)]);
        } catch (PaymentGatewayException $e) {
            Log::info('The payer could not be read from Xendit just now.', ['payment_id' => $payment->getKey(), 'error' => $e->getMessage()]);

            return response()->json([
                'message' => "Xendit could not be reached for the payer's details. Try again in a moment.",
                'error_code' => 'PAYMENT_UNAVAILABLE',
            ], 502);
        }
    }
}
