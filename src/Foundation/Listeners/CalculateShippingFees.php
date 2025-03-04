<?php

declare(strict_types=1);

/**
 * Contains the CalculateShippingFees class.
 *
 * @copyright   Copyright (c) 2023 Vanilo UG
 * @author      Attila Fulop
 * @license     MIT
 * @since       2023-03-05
 *
 */

namespace Vanilo\Foundation\Listeners;

use Vanilo\Adjustments\Models\AdjustmentTypeProxy;
use Vanilo\Cart\Contracts\CartEvent;
use Vanilo\Checkout\Contracts\CheckoutEvent;
use Vanilo\Checkout\Facades\Checkout;
use Vanilo\Shipment\Contracts\ShippingMethod;
use Vanilo\Shipment\Models\ShippingMethodProxy;

class CalculateShippingFees
{
    public function handle(CheckoutEvent|CartEvent $event): void
    {
        if ($event instanceof CheckoutEvent) {
            $checkout = $event->getCheckout();
            $cart = $checkout->getCart();
        } else {
            $cart = $event->getCart();
            Checkout::setCart($cart);
            $checkout = Checkout::getFacadeRoot();
        }

        // @todo Also check if the cart is Adjustable; we're getting a CartManager here which
        //       proxies down calls to the store
        if (null === $cart) {
            return;
        }

        $shippingAdjustments = $cart->adjustments()->byType(AdjustmentTypeProxy::SHIPPING());
        foreach ($shippingAdjustments as $adjustment) {
            $shippingAdjustments->remove($adjustment);
        }

        /** @var ShippingMethod $shippingMethod */
        if (null === $shippingMethod = ShippingMethodProxy::find($checkout->getShippingMethodId())) {
            return;
        }

        $calculator = $shippingMethod->getCalculator();
        if ($adjuster = $calculator->getAdjuster($shippingMethod->configuration())) {
            $cart->adjustments()->create($adjuster);
        }
        $checkout->setShippingAmount($shippingMethod->estimate($checkout)->amount());
    }
}
