<?php

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Native\Mobile\Platform;
use Native\Mobile\Testing\FakeBridge;
use Protendai\Billing\Billing;
use Protendai\Billing\Testing\BillingMacros;

/*
 * No Laravel app needed: a bare container stands in for it, and the core's
 * nativephp_call() polyfill sends every bridge call to a FakeBridge.
 */
require_once dirname(__DIR__).'/vendor/nativephp/mobile/src/jump_bridge_functions.php';

BillingMacros::register();

uses()->beforeEach(function () {
    $container = new Container;
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $container->singleton(Billing::class, fn () => new Billing);

    Platform::set(Platform::ANDROID);
    $this->bridge = FakeBridge::enable();
})->afterEach(function () {
    FakeBridge::disable();
    Platform::set(null);
})->in(__DIR__);

function pluginPath(string $path = ''): string
{
    return dirname(__DIR__).($path === '' ? '' : '/'.$path);
}
