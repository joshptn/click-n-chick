<?php

namespace App\Providers;

use App\Models\Food;
use App\Models\Order;
use App\Policies\FoodPolicy;
use App\Policies\OrderItemPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Food::class => FoodPolicy::class,
        Order::class => OrderItemPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
