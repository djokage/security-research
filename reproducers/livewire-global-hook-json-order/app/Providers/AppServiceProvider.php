<?php

namespace App\Providers;

use App\Livewire\Hooks\RejectNonAdminCalls;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register before Livewire's ComponentHookRegistry installs its listeners.
        Livewire::componentHook(RejectNonAdminCalls::class);
    }

    public function boot(): void
    {
        //
    }
}
