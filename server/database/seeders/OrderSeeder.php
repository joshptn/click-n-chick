<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;


class OrderSeeder extends Seeder
{
    private const TAG = 'DEMO';

    private const DEFAULT_CUSTOMER = 'customer@chicknclick.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('OrderSeeder skipped: it writes fake orders and is for development only.');

            return;
        }

        $foods = Food::query()->orderBy('id')->take(6)->get();

        if ($foods->isEmpty()) {
            $this->command?->error('No foods found. Run FoodSeeder first.');

            return;
        }

        $addons = Addon::query()->orderBy('id')->take(4)->get();

        $this->clearPrevious();

        foreach ($this->targets() as $customer) {
            $this->seedFor($customer, $foods, $addons);

            $this->command?->info("Demo orders created for {$customer->email} - open /orders to see them.");
        }
    }

    private function targets(): Collection
    {
        $requested = env('ORDER_SEED_EMAIL');

        if ($requested) {
            $user = User::where('email', $requested)->first();

            if (! $user) {
                $this->command?->error("No account found for {$requested}.");

                return collect();
            }

            return collect([$user]);
        }

        $seeded = User::where('email', self::DEFAULT_CUSTOMER)
            ->where('role', User::ROLE_CUSTOMER)
            ->first();

        $newest = User::where('role', User::ROLE_CUSTOMER)->latest('id')->first();

        return collect([$seeded, $newest])->filter()->unique('id')->values();
    }

    private function clearPrevious(): void
    {
        Order::query()->where('order_number', 'LIKE', self::TAG.'-%')->delete();
    }

    private function seedFor(User $customer, Collection $foods, Collection $addons): void
    {
        $now = CarbonImmutable::now(config('store.timezone', 'Asia/Manila'));

        foreach ([OrderStatus::PREPARING, OrderStatus::CONFIRMED] as $index => $status) {
            $filler = $this->make($customer, [
                'order_type' => 'pickup',
                'status' => OrderStatus::PLACED,
                'pickup_at' => $now->addHour(),
            ], $foods->take(1), collect(), "filler-{$index}");

            $filler->enterQueue($now->subMinutes(50 - $index * 10)->utc());
            $filler->forceFill(['status' => $status, 'user_id' => $this->otherCustomer($customer)?->id])->save();
        }


        $placed = $this->make($customer, [
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'pickup_at' => $now->addMinutes(45),
        ], $foods->take(2), $addons->take(1), 'placed');
        $placed->enterQueue($now->subMinutes(4)->utc());

        $confirmed = $this->make($customer, [
            'order_type' => 'delivery',
            'status' => OrderStatus::CONFIRMED,
            'full_address' => '27 Rizal Street, San Vicente, Apalit, Pampanga',
            'location' => 'Apalit',
            'latitude' => '14.9612',
            'longitude' => '120.7649',
            'delivery_fee' => 55,
            'delivery_distance_km' => 2.4,
            'delivery_note' => 'Blue gate beside the sari-sari store.',
        ], $foods->slice(1, 2), $addons->take(2), 'confirmed');
        $confirmed->enterQueue($now->subMinutes(12)->utc());

        $preparing = $this->make($customer, [
            'order_type' => 'delivery',
            'status' => OrderStatus::PREPARING,
            'full_address' => '8 Mabini Street, Sulipan, Apalit, Pampanga',
            'location' => 'Apalit',
            'latitude' => '14.9530',
            'longitude' => '120.7710',
            'delivery_fee' => 65,
            'delivery_distance_km' => 4.1,
            'details_confirmed_at' => $now->subMinutes(20),
        ], $foods->slice(2, 2), collect(), 'preparing');
        $preparing->enterQueue($now->subMinutes(28)->utc());

        $ready = $this->make($customer, [
            'order_type' => 'pickup',
            'status' => OrderStatus::READY_FOR_PICKUP,
            'pickup_at' => $now->addMinutes(15),
        ], $foods->take(1), $addons->take(1), 'ready');
        $ready->enterQueue($now->subMinutes(40)->utc());
        $ready->forceFill(['status' => OrderStatus::READY_FOR_PICKUP])->save();

        $onTheWay = $this->make($customer, [
            'order_type' => 'delivery',
            'status' => OrderStatus::ON_THE_WAY,
            'full_address' => '112 Sampaguita Street, Cansinala, Apalit, Pampanga',
            'location' => 'Apalit',
            'latitude' => '14.9448',
            'longitude' => '120.7802',
            'delivery_fee' => 75,
            'delivery_distance_km' => 6.8,
            'estimated_time_of_completion' => 20,
        ], $foods->slice(3, 2), collect(), 'on-the-way');
        $onTheWay->enterQueue($now->subMinutes(55)->utc());
        $onTheWay->forceFill(['status' => OrderStatus::ON_THE_WAY])->save();


        $this->make($customer, [
            'order_type' => 'delivery',
            'status' => OrderStatus::DELIVERED,
            'full_address' => '27 Rizal Street, San Vicente, Apalit, Pampanga',
            'location' => 'Apalit',
            'delivery_fee' => 55,
            'delivery_distance_km' => 2.4,
            'closed_at' => $now->subDay()->setTime(18, 42),
            'created_at' => $now->subDay()->setTime(17, 30),
            'queue_number' => 14,
            'queue_date' => $now->subDay()->toDateString(),
            'queued_at' => $now->subDay()->setTime(17, 31),
        ], $foods->take(2), $addons->take(1), 'delivered');

        $this->make($customer, [
            'order_type' => 'pickup',
            'status' => OrderStatus::COMPLETED,
            'pickup_at' => $now->subDays(3)->setTime(12, 15),
            'closed_at' => $now->subDays(3)->setTime(12, 22),
            'created_at' => $now->subDays(3)->setTime(11, 40),
            'queue_number' => 6,
            'queue_date' => $now->subDays(3)->toDateString(),
            'queued_at' => $now->subDays(3)->setTime(11, 41),
            'discount_rate' => 0.20,
        ], $foods->slice(1, 3), collect(), 'completed');

        $this->make($customer, [
            'order_type' => 'delivery',
            'status' => OrderStatus::CANCELLED,
            'full_address' => '8 Mabini Street, Sulipan, Apalit, Pampanga',
            'location' => 'Apalit',
            'delivery_fee' => 65,
            'delivery_distance_km' => 4.1,
            'cancellation_reason' => 'We ran out of chicken before we could start this one. Sorry!',
            'cancelled_by' => User::where('role', User::ROLE_ADMIN)->value('id'),
            'refund_owed' => null,
            'closed_at' => $now->subDays(5)->setTime(19, 5),
            'created_at' => $now->subDays(5)->setTime(18, 50),
        ], $foods->take(1), collect(), 'cancelled');

        $this->make($customer, [
            'order_type' => 'delivery',
            'status' => OrderStatus::PLACED,
            'full_address' => '27 Rizal Street, San Vicente, Apalit, Pampanga',
            'location' => 'Apalit',
            'latitude' => '14.9612',
            'longitude' => '120.7649',
            'delivery_fee' => 55,
            'delivery_distance_km' => 2.4,
            'payment_status' => 'unpaid',
        ], $foods->take(1), collect(), 'unpaid');
    }

    private function make(
        User $customer,
        array $attributes,
        Collection $foods,
        Collection $addons,
        string $slug,
    ): Order {
        $discountRate = $attributes['discount_rate'] ?? 0;
        unset($attributes['discount_rate']);

        $lines = $foods->map(fn (Food $food, int $index) => [
            'food' => $food,
            'quantity' => $index === 0 ? 1 : 2,
            'addons' => $index === 0 ? $addons : collect(),
        ]);

        $subtotal = $lines->sum(function (array $line) {
            $addonTotal = (float) $line['addons']->sum('addon_price');

            return ((float) $line['food']->price + $addonTotal) * $line['quantity'];
        });

        $deliveryFee = (float) ($attributes['delivery_fee'] ?? 0);
        $discount = round($subtotal * $discountRate, 2);
        $total = round($subtotal - $discount + $deliveryFee, 2);

        $attributes = array_map(
            fn ($value) => $value instanceof CarbonInterface ? CarbonImmutable::parse($value)->utc() : $value,
            $attributes
        );

        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'order_number' => self::TAG.'-'.$slug.'-'.$customer->id,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => $discount,
            'total_amount' => $total,
            'total_price' => $total,
            'payment_status' => 'paid',
        ], $attributes));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        foreach ($lines as $line) {
            $addonTotal = (float) $line['addons']->sum('addon_price');
            $unitPrice = (float) $line['food']->price + $addonTotal;

            $item = OrderItem::create([
                'order_id' => $order->id,
                'food_id' => $line['food']->id,
                'quantity' => $line['quantity'],
                'price' => $unitPrice,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $line['quantity'], 2),
            ]);

            foreach ($line['addons'] as $addon) {
                OrderItemAddon::create([
                    'order_item_id' => $item->id,
                    'addon_id' => $addon->id,
                    'unit_price' => $addon->addon_price,
                ]);
            }
        }

        return $order->fresh();
    }

    private function otherCustomer(User $exclude): ?User
    {
        return User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->whereKeyNot($exclude->getKey())
            ->first();
    }
}
