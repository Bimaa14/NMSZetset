<?php

namespace App\Providers;

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
        \Illuminate\Support\Facades\Queue::looping(fn()=>\Illuminate\Support\Facades\Cache::put('nms:worker:heartbeat',now()->toIso8601String(),600));
        \Illuminate\Support\Facades\Gate::define('api', fn (\App\Models\User $user) => $user->allows('api'));
        \Illuminate\Support\Facades\Gate::define('manage-tags', fn (\App\Models\User $user) => $user->allows('tags'));
        \Illuminate\Support\Facades\Gate::define('manage-staff', fn (\App\Models\User $user) => $user->allows('staff'));
        \Illuminate\Support\Facades\Gate::define('view-audit', fn (\App\Models\User $user) => $user->allows('audit'));
    }
}
