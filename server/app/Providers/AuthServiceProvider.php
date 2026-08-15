<?php

namespace App\Providers;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Food;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Poster;
use App\Models\StockHold;
use App\Models\User;
use App\Policies\AddressPolicy;
use App\Policies\CartItemPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\DiscountPolicy;
use App\Policies\FoodPolicy;
use App\Policies\LoyaltyTransactionPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PosterPolicy;
use App\Policies\StockHoldPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;


class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        Address::class => AddressPolicy::class,
        CartItem::class => CartItemPolicy::class,
        Category::class => CategoryPolicy::class,
        Discount::class => DiscountPolicy::class,
        Food::class => FoodPolicy::class,
        LoyaltyTransaction::class => LoyaltyTransactionPolicy::class,
        Order::class => OrderPolicy::class,
        OrderItem::class => OrderItemPolicy::class,
        Payment::class => PaymentPolicy::class,
        Poster::class => PosterPolicy::class,
        StockHold::class => StockHoldPolicy::class,
        User::class => UserPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
