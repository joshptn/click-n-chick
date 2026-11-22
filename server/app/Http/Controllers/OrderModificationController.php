<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveGuestOrder;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\Orders\AmendmentPolicy;
use App\Services\Orders\CheckoutQuote;
use App\Services\Orders\OrderAnnouncer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Changing an order's contact name and mobile number (BR-21b, UC-ORD-008).
 *
 * The only details that can change, by the customer or a guest from their
 * tracking link, until the order closes. The order's own copy changes; the
 * customer's profile does not. A Store Agent cannot change them (decided
 * 2026-11-17), and nothing else on an order can be changed at all.
 *
 * A guest may also correct the email their tracking link is sent to, from the
 * link's resend card (FR-02.13b, decided 2026-11-22). Saving it sends
 * nothing; the guest presses Resend link after.
 */
class OrderModificationController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'items.addons', 'user'];

    /** Fields that used to be changeable, refused by name so the reason is clear. */
    private const LOCKED = ['address_id', 'latitude', 'longitude', 'full_address', 'location', 'delivery_note', 'pickup_at'];

    public function __construct(
        private AmendmentPolicy $amendment,
        private OrderAnnouncer $announcer,
    ) {}

    public function update(Request $request, Order $order)
    {
        $this->authorize('amend', $order);

        return $this->change($request, $order);
    }

    public function guestUpdate(Request $request)
    {
        return $this->change($request, $request->attributes->get(ResolveGuestOrder::ATTRIBUTE));
    }

    private function change(Request $request, Order $order)
    {
        if ($order->isUnpaidCheckout()) {
            abort(404);
        }

        if ($request->hasAny(self::LOCKED)) {
            return $this->refuse(
                'Only the contact name and mobile number can be changed on an order.',
                'NOT_CHANGEABLE'
            );
        }

        $validated = $request->validate([
            // Nullable so a blank (trimmed to null) gets the message below.
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // A customer's email is their account's; only a guest's order carries one.
        if (array_key_exists('contact_email', $validated) && ! $order->isGuest()) {
            return $this->refuse(
                'Only the contact name and mobile number can be changed on an order.',
                'NOT_CHANGEABLE'
            );
        }

        if (! $this->amendment->canEditContact($order)) {
            return $this->refuse('This order has closed, so its details can no longer be changed.', 'ORDER_ALREADY_CLOSED');
        }

        $changes = [];

        if (array_key_exists('contact_name', $validated)) {
            $name = trim((string) $validated['contact_name']);

            if ($name === '') {
                return $this->refuse('Enter the name of whoever will receive or collect the order.', 'CONTACT_NAME_REQUIRED');
            }

            $changes['contact_name'] = $name;
        }

        if (array_key_exists('contact_phone', $validated)) {
            $phone = CheckoutQuote::normalizePhone($validated['contact_phone']);

            if ($phone === null) {
                return $this->refuse('Enter an 11-digit mobile number, starting 09.', 'CONTACT_PHONE_INVALID');
            }

            $changes['contact_phone'] = $phone;
        }

        if (array_key_exists('contact_email', $validated)) {
            $email = trim((string) $validated['contact_email']);

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->refuse('That email address does not look right. Please check it.', 'CONTACT_EMAIL_INVALID');
            }

            $changes['guest_email'] = $email;
        }

        if ($changes === []) {
            return $this->refuse('There was nothing to change.', 'NOTHING_TO_CHANGE');
        }

        $order->forceFill($changes)->save();

        // The staff boards show the new contact at once.
        $this->announcer->announce($order, 'update');

        return response()->json([
            'message' => array_keys($changes) === ['guest_email']
                ? 'Your email address for this order has been updated.'
                : 'Your contact details for this order have been updated.',
            'order' => new OrderTrackingResource($order->load(self::WITH)),
        ]);
    }

    private function refuse(string $message, string $code)
    {
        return response()->json(['message' => $message, 'error_code' => $code], 422);
    }
}
