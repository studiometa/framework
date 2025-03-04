<?php

declare(strict_types=1);

/**
 * Contains the OrderFactory class.
 *
 * @copyright   Copyright (c) 2017 Attila Fulop
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-11-30
 *
 */

namespace Vanilo\Order\Factories;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Konekt\Address\Contracts\AddressType;
use Konekt\Address\Models\AddressProxy;
use Konekt\Address\Models\AddressTypeProxy;
use ReflectionFunction;
use Vanilo\Contracts\Address;
use Vanilo\Contracts\Buyable;
use Vanilo\Order\Contracts\Billpayer;
use Vanilo\Order\Contracts\Order;
use Vanilo\Order\Contracts\OrderFactory as OrderFactoryContract;
use Vanilo\Order\Contracts\OrderItem;
use Vanilo\Order\Contracts\OrderNumberGenerator;
use Vanilo\Order\Events\OrderWasCreated;
use Vanilo\Order\Exceptions\CreateOrderException;

class OrderFactory implements OrderFactoryContract
{
    /** @var OrderNumberGenerator */
    protected $orderNumberGenerator;

    public function __construct(OrderNumberGenerator $generator)
    {
        $this->orderNumberGenerator = $generator;
    }

    /**
     * @inheritDoc
     */
    public function createFromDataArray(array $data, array $items, callable ...$hooks): Order
    {
        if (empty($items)) {
            throw new CreateOrderException(__('Can not create an order without items'));
        }

        DB::beginTransaction();

        try {
            $order = app(Order::class);

            $order->fill(Arr::except($data, ['billpayer', 'shippingAddress']));
            $order->number = $data['number'] ?? $this->orderNumberGenerator->generateNumber($order);
            $order->user_id = $data['user_id'] ?? auth()->id();
            $order->save();

            $this->createBillpayer($order, $data);
            $this->createShippingAddress($order, $data);

            $this->createItems(
                $order,
                array_map(function ($item) {
                    // Default quantity is 1 if unspecified
                    $item['quantity'] = $item['quantity'] ?? 1;

                    return $item;
                }, $items)
            );

            foreach ($hooks as $hook) {
                $this->callHook($hook, $order, $data, $items);
            }

            $order->save();
        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }

        DB::commit();

        event(new OrderWasCreated($order));

        return $order;
    }

    protected function createShippingAddress(Order $order, array $data)
    {
        if ($address = isset($data['shippingAddress'])) {
            $order->shippingAddress()->associate(
                $this->createOrCloneAddress($data['shippingAddress'], AddressTypeProxy::SHIPPING())
            );
        }
    }

    protected function createBillpayer(Order $order, array $data)
    {
        if (isset($data['billpayer'])) {
            $address = $this->createOrCloneAddress($data['billpayer']['address'], AddressTypeProxy::BILLING());

            $billpayer = app(Billpayer::class);
            $billpayer->fill(Arr::except($data['billpayer'], 'address'));
            $billpayer->address()->associate($address);
            $billpayer->save();

            $order->billpayer()->associate($billpayer);
        }
    }

    protected function createItems(Order $order, array $items, callable ...$hooks)
    {
        $that = $this;
        $hasBuyables = collect($items)->contains(function ($item) use ($that) {
            return $that->itemContainsABuyable($item);
        });

        if (!$hasBuyables) { // This is faster
            $order->items()->createMany($items);
            foreach ($order->getItems() as $createdOrderItem) {
                foreach ($hooks as $hook) {
                    $this->callItemHook($hook, $createdOrderItem, $order, $items);
                }
            }
        } else {
            foreach ($items as $item) {
                $createdOrderItem = $this->createItem($order, $item);
                foreach ($hooks as $hook) {
                    $this->callItemHook($hook, $createdOrderItem, $order, $items);
                }
            }
        }
    }

    /**
     * Creates a single item for the given order
     *
     * @param Order $order
     * @param array $item
     */
    protected function createItem(Order $order, array $item): OrderItem
    {
        if ($this->itemContainsABuyable($item)) {
            /** @var Buyable $product */
            $product = $item['product'];
            $item = array_merge($item, [
                'product_type' => $product->morphTypeName(),
                'product_id' => $product->getId(),
                'price' => $product->getPrice(),
                'name' => $product->getName()
            ]);
            unset($item['product']);
        }

        return $order->items()->create($item);
    }

    /**
     * @throws \ReflectionException
     */
    protected function callHook(callable $hook, mixed $order, array $data, array $items): void
    {
        $ref = new ReflectionFunction($hook);
        match ($ref->getNumberOfParameters()) {
            0 => $hook(),
            1 => $hook($order),
            2 => $hook($order, $data),
            default => $hook($order, $data, $items),
        };
    }

    /**
     * @throws \ReflectionException
     */
    protected function callItemHook(callable $hook, OrderItem $orderItem, Order $order, array $sourceItems): void
    {
        $ref = new ReflectionFunction($hook);
        match ($ref->getNumberOfParameters()) {
            0 => $hook(),
            1 => $hook($orderItem),
            2 => $hook($orderItem, $order),
            default => $hook($orderItem, $order, $sourceItems),
        };
    }

    /**
     * Returns whether an instance contains a buyable object
     *
     * @param array $item
     *
     * @return bool
     */
    private function itemContainsABuyable(array $item)
    {
        return isset($item['product']) && $item['product'] instanceof Buyable;
    }

    private function addressToAttributes(Address $address)
    {
        return [
            'name' => $address->getName(),
            'postalcode' => $address->getPostalCode(),
            'country_id' => $address->getCountryCode(),
            /** @todo Convert Province code to province_id */
            'city' => $address->getCity(),
            'address' => $address->getAddress(),
        ];
    }

    private function createOrCloneAddress($address, AddressType $type = null)
    {
        if ($address instanceof Address) {
            $address = $this->addressToAttributes($address);
        } elseif (!is_array($address)) {
            throw new CreateOrderException(
                sprintf(
                    'Address data is %s but it should be either an Address or an array',
                    gettype($address)
                )
            );
        }

        $type = is_null($type) ? AddressTypeProxy::defaultValue() : $type->value();
        $address['type'] = $type;
        $address['name'] = empty(Arr::get($address, 'name')) ? '-' : $address['name'];

        return AddressProxy::create($address);
    }
}
