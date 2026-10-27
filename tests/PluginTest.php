<?php

use Protendai\Billing\Billing;

/**
 * Manifest ↔ facade ↔ native code consistency.
 */
beforeEach(function () {
    $this->manifest = json_decode(file_get_contents(pluginPath('nativephp.json')), true);
    $this->kotlin = file_get_contents(pluginPath('resources/android/src/BillingFunctions.kt'));
    $this->swift = file_get_contents(pluginPath('resources/ios/Sources/ProtendaiBillingFunctions.swift'));
    $this->composer = json_decode(file_get_contents(pluginPath('composer.json')), true);
});

it('has marketplace metadata', function () {
    expect($this->manifest)->toHaveKeys(['name', 'version', 'description', 'namespace', 'keywords', 'category', 'license', 'author', 'homepage', 'repository', 'platforms', 'bridge_functions', 'events'])
        ->and($this->manifest['name'])->toBe('protendai/nativephp-billing')
        ->and($this->manifest['namespace'])->toBe('ProtendaiBilling')
        ->and($this->manifest['license'])->toBe('MIT')
        ->and($this->manifest['platforms'])->toBe(['android', 'ios'])
        ->and($this->composer['name'])->toBe('protendai/nativephp-billing')
        ->and($this->composer['license'])->toBe('MIT')
        ->and($this->composer['keywords'])->toContain('nativephp-plugin')
        ->and($this->composer['require'])->toHaveKey('nativephp/mobile')
        ->and(file_exists(pluginPath('LICENSE')))->toBeTrue();
});

it('needs Android 5.0 (Play Billing 8 minimum) and the billing permission', function () {
    expect($this->manifest['android']['min_version'])->toBe(21)
        ->and($this->manifest['android']['permissions'])->toBe(['com.android.vending.BILLING'])
        ->and($this->manifest['android']['dependencies']['implementation'])->toBe(['com.android.billingclient:billing:8.0.0'])
        ->and($this->manifest['ios']['min_version'])->toBe('15.0');
});

it('maps every bridge function to the facade constants and both native classes', function () {
    $functions = ['Products', 'Purchase', 'Restore', 'Acknowledge', 'Consume'];

    expect(array_map(fn ($f) => [$f['name'], $f['android'], $f['ios']], $this->manifest['bridge_functions']))->toBe(
        array_map(fn ($name) => ["ProtendaiBilling.$name", "com.protendai.billing.BillingFunctions.$name", "ProtendaiBillingFunctions.$name"], $functions)
    );

    expect([Billing::PRODUCTS, Billing::PURCHASE, Billing::RESTORE, Billing::ACKNOWLEDGE, Billing::CONSUME])
        ->toBe(array_column($this->manifest['bridge_functions'], 'name'));

    expect($this->kotlin)->toContain('package com.protendai.billing')->toContain('object BillingFunctions');
    expect($this->swift)->toContain('enum ProtendaiBillingFunctions');
    foreach ($functions as $name) {
        expect($this->kotlin)->toContain("class $name(private val activity: FragmentActivity) : BridgeFunction")
            ->and($this->swift)->toContain("class $name: BridgeFunction");
    }
});

it('uses StoreKit 2 on iOS, with the same events and the PHP account token namespace', function () {
    expect($this->swift)
        ->toContain('import StoreKit')
        ->toContain('Transaction.updates')
        ->toContain('Transaction.currentEntitlements')
        ->toContain('.appAccountToken(')
        ->toContain('"Protendai\\\\Billing\\\\Events\\\\" + event')
        ->toContain('UUID(uuidString: "'.Billing::ACCOUNT_TOKEN_NAMESPACE.'")')
        ->not->toContain('UNSUPPORTED')
        ->not->toMatch('/ftree/i');

    foreach ($this->manifest['events'] as $event) {
        $short = class_basename($event);
        expect($this->swift)->toMatch('/(dispatch\\("|event: ")'.$short.'"/');

        foreach ((new ReflectionClass($event))->getConstructor()->getParameters() as $parameter) {
            if (! $parameter->isVariadic()) {
                expect($this->swift)->toContain('"'.$parameter->getName().'"');
            }
        }
    }
});

it('declares every event, and the Kotlin dispatches each with keys the constructor takes', function () {
    expect($this->kotlin)->toContain('internal const val EVENTS = "Protendai\\\\Billing\\\\Events\\\\"');

    foreach ($this->manifest['events'] as $event) {
        expect(class_exists($event))->toBeTrue();
        $short = class_basename($event);
        expect($this->kotlin)->toContain("dispatch(\"$short\"");

        foreach ((new ReflectionClass($event))->getConstructor()->getParameters() as $parameter) {
            if (! $parameter->isVariadic()) {
                expect($this->kotlin)->toContain('"'.$parameter->getName().'"');
            }
        }
    }
});

it('uses Play Billing 8 APIs for the audit fixes', function () {
    expect($this->kotlin)
        ->toContain('enableAutoServiceReconnection()')
        ->toContain('WeakReference<FragmentActivity>')
        ->toContain('failWaiting(')
        ->toContain('is JSONArray ->')
        ->not->toContain('com.protendai.plugins')
        ->not->toMatch('/ftree/i');
});

it('exports a JavaScript function for every bridge function', function () {
    $js = file_get_contents(pluginPath('resources/js/index.js'));

    foreach ($this->manifest['bridge_functions'] as $function) {
        expect($js)->toContain("'{$function['name']}'");
    }
    foreach ($this->manifest['events'] as $event) {
        expect($js)->toContain(str_replace('\\', '\\\\', $event));
    }
    expect(file_exists(pluginPath('resources/js/index.d.ts')))->toBeTrue();
});

it('ships boost guidelines that cover the API', function () {
    $guide = file_get_contents(pluginPath('resources/boost/guidelines/core.blade.php'));

    foreach (['products(', 'subscribe(', 'buy(', 'restore(', 'acknowledge(', 'consume(', 'ProductsLoaded', 'PurchaseUpdated', 'PurchasesRestored', 'PurchaseAcknowledged', 'PurchaseConsumed', 'already_owned'] as $needle) {
        expect($guide)->toContain($needle);
    }
});
