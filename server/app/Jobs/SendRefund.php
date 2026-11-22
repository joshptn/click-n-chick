<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentRefunds;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send a GCash refund to Xendit (decided 2026-11-23).
 *
 * Queued after the cancellation commits, so the Xendit call never holds a
 * database lock. Only the payment's id is queued. When Xendit cannot be
 * reached the job tries again; each try first looks for a refund an earlier
 * try may have sent, so a lost answer never becomes a second refund. After
 * the last try it becomes a refund by hand.
 */
class SendRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public int $paymentId,
        public string $reason,
    ) {}

    public function handle(PaymentRefunds $refunds): void
    {
        $payment = Payment::find($this->paymentId);

        if ($payment === null || $payment->refund_status !== Payment::REFUND_PENDING) {
            return;
        }

        try {
            $refunds->send($payment, $this->reason);
        } catch (PaymentGatewayException $e) {
            // Run inline (the sync queue: local, tests) there is no later try,
            // and the cancellation that queued it must not fail with it.
            if ($this->job === null || $this->job->getConnectionName() === 'sync') {
                $this->failed($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $moved = Payment::query()
            ->whereKey($this->paymentId)
            ->where('refund_status', Payment::REFUND_PENDING)
            ->whereNull('refund_reference_id')
            ->update(['refund_status' => Payment::REFUND_MANUAL]);

        if ($moved > 0) {
            Log::warning('Xendit could not be reached for a GCash refund; it is now a refund by hand.', [
                'payment_id' => $this->paymentId,
                'error' => $e?->getMessage(),
            ]);
        }
    }
}
