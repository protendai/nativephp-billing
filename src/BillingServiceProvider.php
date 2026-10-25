<?php

namespace Protendai\Billing;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Testing\FakeBridge;
use Protendai\Billing\Testing\BillingMacros;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Billing::class, fn () => new Billing);

        // Test sugar (assertPurchaseStarted() etc.), only under a test runner and on a core with a macroable FakeBridge.
        if ($this->app->runningUnitTests()
            && class_exists(FakeBridge::class)
            && method_exists(FakeBridge::class, 'macro')) {
            BillingMacros::register();
        }
    }
}
