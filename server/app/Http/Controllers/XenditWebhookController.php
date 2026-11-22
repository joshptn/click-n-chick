<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentRefunds;
use App\Services\Payments\PaymentSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Xendit's payment and refund notifications (FR-04.3, BR-21d).
 *
 * Authenticated by the verification token Xendit sends in x-callback-token.
 * The body is used only to find which of our payments it is about: what the
 * payment or refund actually did is then read back from Xendit, so even a
 * forged notification with a stolen token cannot mark anything paid or
 * refunded.
 *
 * Point the "Payment Session", "Payments" and "Refunds" webhooks in the
 * Xendit dashboard here: payment_session.completed / .expired, payment.capture,
 * and refund.succeeded / refund.failed.
 */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentSettlement $settlement, PaymentRefunds $refunds, PaymentGateway $gateway)
    {
        $expected = (string) config('services.xendit.webhook_token');
        $given = (string) $request->header('x-callback-token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid callback token.'], 401);
        }

        $event = (string) $request->input('event', '');
        $data = (array) $request->input('data', []);

        if (str_starts_with($event, 'refund.')) {
            return $this->refund($event, $data, $refunds, $gateway);
        }

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

    /**
     * A refund finished at Xendit. Matched by its id, or by our reference when
     * the notification beats the queued job to saving the id.
     */
    private function refund(string $event, array $data, PaymentRefunds $refunds, PaymentGateway $gateway)
    {
        $refundId = (string) ($data['refund_id'] ?? $data['id'] ?? '');

        $payment = ($refundId === '' ? null : Payment::query()->where('refund_reference_id', $refundId)->first())
            ?? Payment::forRefundReference($data['reference_id'] ?? null);

        if ($payment === null || $refundId === '' || $payment->refund_status === Payment::REFUND_REFUNDED) {
            return response()->json(['received' => true]);
        }

        try {
            $refund = $gateway->getRefund($refundId);
        } catch (PaymentGatewayException $e) {
            Log::warning('A refund notification could not be checked with Xendit; it will be retried.', [
                'payment_id' => $payment->getKey(),
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Try again later.'], 503);
        }

        // Ours only if Xendit says it was made under this payment's reference.
        if ($refund->reference !== $payment->refundReference()) {
            return response()->json(['received' => true]);
        }

        if ($payment->refund_reference_id === null) {
            $payment->forceFill(['refund_reference_id' => $refund->id])->save();
        }

        $refunds->apply($payment->refresh(), $refund);

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
