<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Middleware\ResolveGuestOrder;
use App\Mail\GuestTrackingLinkMail;
use App\Models\Order;
use App\Services\Orders\GuestTrackingLink;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Resending a guest's tracking link from the tracking page (FR-02.13b,
 * decided 2026-11-22): the card's details, one send per 30 seconds counted
 * from the automatic email too, 10 per order, only while the order is open,
 * and a failed send that costs nothing.
 *
 * The automatic email on payment is covered in PaymentFlowTest.
 */
class GuestTrackingLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila')->utc());

        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Order, 1: string} a paid, open guest order and its token */
    private function guestOrder(array $overrides = []): array
    {
        $order = $this->orderWith(array_merge([
            'user_id' => null,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'order_number' => '004',
            'subtotal' => 350,
            'total_price' => 350,
            'total_amount' => 350,
            'payment_status' => 'paid',
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
            'guest_email' => 'julia@example.test',
        ], $overrides));

        return [$order, $order->mintGuestToken()];
    }

    private function resend(string $token)
    {
        return $this->withHeader(ResolveGuestOrder::HEADER, $token)->postJson('/api/guest/order/link');
    }

    public function test_the_tracking_page_carries_the_cards_details(): void
    {
        [, $token] = $this->guestOrder();

        $this->withHeader(ResolveGuestOrder::HEADER, $token)
            ->getJson('/api/guest/order')
            ->assertOk()
            ->assertJsonPath('link.email', 'julia@example.test')
            ->assertJsonPath('link.resend_in', 0)
            ->assertJsonPath('link.resends_left', 10);
    }

    public function test_a_resend_emails_the_link_the_guest_is_holding(): void
    {
        [, $token] = $this->guestOrder();

        $this->resend($token)
            ->assertOk()
            ->assertJsonPath('message', 'Your tracking link has been sent. Please check your email.')
            ->assertJsonPath('link.resend_in', 30)
            ->assertJsonPath('link.resends_left', 9);

        Mail::assertSent(GuestTrackingLinkMail::class, function (GuestTrackingLinkMail $mail) use ($token) {
            return $mail->hasTo('julia@example.test')
                && $mail->url === config('app.frontend_url').'/track#'.$token;
        });
    }

    public function test_a_corrected_email_gets_the_next_resend(): void
    {
        [, $token] = $this->guestOrder();

        $this->withHeader(ResolveGuestOrder::HEADER, $token)
            ->patchJson('/api/guest/order', ['contact_email' => 'julia.v@example.test'])
            ->assertOk();

        Mail::assertNothingSent();

        $this->resend($token)->assertOk()->assertJsonPath('link.email', 'julia.v@example.test');

        Mail::assertSent(GuestTrackingLinkMail::class, fn (GuestTrackingLinkMail $mail) => $mail->hasTo('julia.v@example.test'));
    }

    public function test_one_send_per_thirty_seconds(): void
    {
        [, $token] = $this->guestOrder();

        $this->resend($token)->assertOk();

        $this->travel(20)->seconds();

        $this->resend($token)
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'LINK_RESEND_WAIT')
            ->assertJsonPath('link.resend_in', 10);

        $this->travel(11)->seconds();

        $this->resend($token)->assertOk();

        Mail::assertSent(GuestTrackingLinkMail::class, 2);
    }

    public function test_the_automatic_email_starts_the_wait_too(): void
    {
        [$order, $token] = $this->guestOrder();

        app(GuestTrackingLink::class)->sendFirst($order);

        $this->resend($token)->assertStatus(429)->assertJsonPath('error_code', 'LINK_RESEND_WAIT');

        // The automatic email is not one of the ten.
        $this->travel(31)->seconds();
        $this->resend($token)->assertOk()->assertJsonPath('link.resends_left', 9);
    }

    public function test_ten_resends_per_order(): void
    {
        [, $token] = $this->guestOrder();

        for ($i = 1; $i <= 10; $i++) {
            $this->resend($token)->assertOk()->assertJsonPath('link.resends_left', 10 - $i);
            $this->travel(31)->seconds();
        }

        $this->resend($token)
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'LINK_RESEND_LIMIT')
            ->assertJsonPath('link.resends_left', 0)
            ->assertJsonPath('link.resend_in', 0);

        Mail::assertSent(GuestTrackingLinkMail::class, 10);
    }

    public function test_the_limits_come_from_configuration(): void
    {
        config()->set('store.guest.link_resends_per_order', 1);
        config()->set('store.guest.link_resend_wait_seconds', 5);

        [, $token] = $this->guestOrder();

        $this->resend($token)->assertOk()->assertJsonPath('link.resend_in', 5);
        $this->travel(6)->seconds();
        $this->resend($token)->assertStatus(429)->assertJsonPath('error_code', 'LINK_RESEND_LIMIT');
    }

    public function test_a_failed_send_costs_nothing(): void
    {
        [, $token] = $this->guestOrder();

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Gmail is down'));

        $this->resend($token)
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'LINK_NOT_SENT')
            ->assertJsonPath('link.resend_in', 0)
            ->assertJsonPath('link.resends_left', 10);
    }

    public function test_one_orders_sends_do_not_count_against_another(): void
    {
        [, $first] = $this->guestOrder();
        [, $second] = $this->guestOrder(['order_number' => '005']);

        $this->resend($first)->assertOk();
        $this->resend($second)->assertOk()->assertJsonPath('link.resends_left', 9);
    }

    public function test_nothing_is_resent_once_the_order_has_closed(): void
    {
        [, $token] = $this->guestOrder([
            'status' => OrderStatus::COMPLETED,
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->withHeader(ResolveGuestOrder::HEADER, $token)
            ->getJson('/api/guest/order')
            ->assertOk()
            ->assertJsonPath('link', null);

        $this->resend($token)->assertStatus(422)->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');

        Mail::assertNothingSent();
    }

    public function test_an_unpaid_checkout_has_no_link_to_send(): void
    {
        [, $token] = $this->guestOrder([
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
            'order_number' => null,
        ]);

        $this->resend($token)->assertStatus(422)->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');

        Mail::assertNothingSent();
    }

    /** A link handed out before tokens were derived: the token held is the one sent. */
    public function test_a_link_from_before_can_be_resent(): void
    {
        [$order] = $this->guestOrder();

        $old = bin2hex(random_bytes(32));
        $order->forceFill(['guest_token_hash' => Order::hashGuestToken($old)])->save();

        $this->resend($old)->assertOk();

        Mail::assertSent(GuestTrackingLinkMail::class, fn (GuestTrackingLinkMail $mail) => str_ends_with($mail->url, '#'.$old));
    }

    public function test_a_wrong_token_is_refused_before_anything_is_sent(): void
    {
        $this->guestOrder();

        $this->resend(bin2hex(random_bytes(32)))->assertNotFound();

        Mail::assertNothingSent();
    }
}
