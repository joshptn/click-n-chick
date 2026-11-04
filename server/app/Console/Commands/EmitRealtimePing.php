<?php

namespace App\Console\Commands;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

/**
 * Emit a real broadcast, for end-to-end verification (GATE-3).
 *
 * Deliberately NOT a fake. It dispatches the same events OrderController and
 * Utils\Notification dispatch, through the same Reverb connection, onto the
 * same channels - so a browser receiving one of these has proven the whole
 * path works, not that a test double fired.
 *
 * The events publish immediately and a failed publish is logged, not thrown
 * (App\Events\LiveBroadcast). So Reverb is asked first whether it is
 * answering, rather than letting the ping vanish into the log.
 */
class EmitRealtimePing extends Command
{
    protected $signature = 'realtime:ping
                            {--user= : User id to notify (defaults to the first customer)}
                            {--order= : Broadcast an order status change instead}
                            {--status=preparing : Status to set when using --order}
                            {--title=Order update : Notification title}
                            {--body= : Notification body}';

    protected $description = 'Dispatch a real broadcast so a browser can be verified to receive it';

    public function handle(): int
    {
        if (config('broadcasting.default') !== 'reverb') {
            $this->error('BROADCAST_CONNECTION is "'.config('broadcasting.default').'", not "reverb". Nothing will reach a browser.');

            return self::FAILURE;
        }

        try {
            Broadcast::connection('reverb')->getPusher()->get('/channels', [], true);
        } catch (Throwable $e) {
            $this->error('Reverb is not answering at '.$this->reverbAddress().': '.strtok($e->getMessage(), '('));

            return self::FAILURE;
        }

        return $this->option('order') ? $this->emitOrder() : $this->emitNotification();
    }

    private function emitNotification(): int
    {
        $userId = $this->option('user')
            ?: User::where('role', User::ROLE_CUSTOMER)->value('id');

        if (! $userId) {
            $this->error('No user to notify. Pass --user, or seed the database first.');

            return self::FAILURE;
        }

        $body = $this->option('body') ?: 'Realtime check at '.now()->toTimeString();

        $notification = Notification::create([
            'user_id' => $userId,
            'title' => (string) $this->option('title'),
            'body' => $body,
            'is_read' => false,
        ]);

        NotificationBroadcast::dispatch($notification, (int) $userId);

        $this->info('Dispatched NotificationBroadcast.');
        $this->line('  channel : private-notifications.'.$userId);
        $this->line('  event   : .notification');
        $this->line('  body    : '.$body);

        $this->published();

        return self::SUCCESS;
    }

    private function emitOrder(): int
    {
        $order = Order::find($this->option('order'));

        if (! $order) {
            $this->error('Order '.$this->option('order').' not found.');

            return self::FAILURE;
        }

        // A real status change, then the same broadcast updateOrderStatus makes.
        $order->status = (string) $this->option('status');
        $order->save();

        OrderBroadcast::dispatch($order->load('items'), 'update');

        $this->info('Dispatched OrderBroadcast.');
        $this->line('  channels : private-orders.'.$order->id.', private-admin.orders');
        $this->line('  event    : .order');
        $this->line('  status   : '.$order->status);

        $this->published();

        return self::SUCCESS;
    }

    private function published(): void
    {
        $this->newLine();
        $this->info('Published to Reverb at '.$this->reverbAddress().'.');
    }

    private function reverbAddress(): string
    {
        $options = config('broadcasting.connections.reverb.options');

        return $options['scheme'].'://'.$options['host'].':'.$options['port'];
    }
}
