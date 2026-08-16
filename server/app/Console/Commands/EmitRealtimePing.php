<?php

namespace App\Console\Commands;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emit a real broadcast, for end-to-end verification (GATE-3).
 *
 * Deliberately NOT a fake. It dispatches the same events OrderController and
 * Utils\Notification dispatch, through the same Reverb connection, onto the
 * same channels - so a browser receiving one of these has proven the whole
 * path works, not that a test double fired.
 *
 * Note the queue: these events implement ShouldBroadcast, and
 * QUEUE_CONNECTION is 'database', so nothing reaches Reverb unless
 * `php artisan queue:work` is running. That is a real operational dependency,
 * not a test artefact - the command reports it rather than letting the
 * broadcast vanish silently.
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

        $pending = $this->pendingBroadcastJobs();

        if ($this->option('order')) {
            return $this->emitOrder($pending);
        }

        return $this->emitNotification($pending);
    }

    private function emitNotification(int $pendingBefore): int
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

        $this->reportQueue($pendingBefore);

        return self::SUCCESS;
    }

    private function emitOrder(int $pendingBefore): int
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

        $this->reportQueue($pendingBefore);

        return self::SUCCESS;
    }

    private function pendingBroadcastJobs(): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        try {
            return (int) \DB::table('jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function reportQueue(int $pendingBefore): void
    {
        if (config('queue.default') === 'sync') {
            $this->newLine();
            $this->info('QUEUE_CONNECTION=sync - the broadcast went out immediately.');

            return;
        }

        $now = $this->pendingBroadcastJobs();

        $this->newLine();
        $this->warn('These events implement ShouldBroadcast and QUEUE_CONNECTION is "'.config('queue.default').'".');

        if ($now > $pendingBefore) {
            $this->warn('The broadcast is QUEUED and has not reached Reverb yet.');
            $this->line('Run a worker for it to be delivered:  php artisan queue:work');
            $this->line('Pending jobs: '.$now);
        } else {
            $this->info('A worker appears to be draining the queue (pending jobs: '.$now.').');
        }
    }
}
