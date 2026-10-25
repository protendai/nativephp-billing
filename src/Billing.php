<?php

namespace Ftree\Billing;

/**
 * Google Play subscriptions. Every call answers later with an event:
 * ProductsLoaded, PurchaseUpdated or PurchasesRestored.
 */
class Billing
{
    /** Whether the phone can buy through Google Play (Android only). */
    public function available(): bool
    {
        return function_exists('nativephp_call') && ! (function_exists('isIos') && isIos());
    }

    /** @param  list<string>  $productIds */
    public function products(array $productIds): void
    {
        $this->call('FtreeBilling.Products', ['productIds' => array_values($productIds)]);
    }

    /**
     * Opens Play's purchase sheet. $accountId ties the purchase to the tree;
     * $replacing is the purchase token of a subscription this one replaces.
     */
    public function subscribe(string $productId, string $basePlanId, string $accountId, ?string $replacing = null): void
    {
        $this->call('FtreeBilling.Subscribe', array_filter([
            'productId' => $productId,
            'basePlanId' => $basePlanId,
            'accountId' => $accountId,
            'oldPurchaseToken' => $replacing,
        ]));
    }

    public function restore(): void
    {
        $this->call('FtreeBilling.Restore');
    }

    protected function call(string $function, array $parameters = []): void
    {
        if ($this->available()) {
            nativephp_call($function, json_encode((object) $parameters));
        }
    }
}
