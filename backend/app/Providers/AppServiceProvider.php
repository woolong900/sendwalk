<?php

namespace App\Providers;

use App\Models\ListSubscriber;
use App\Observers\ListSubscriberObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 注册观察者
        ListSubscriber::observe(ListSubscriberObserver::class);
    }
}

