<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

use Utopia\Database\Query;

enum Join: string
{
    case Inner = 'join';
    case Left = 'leftJoin';
    case Right = 'rightJoin';
    case Cross = 'crossJoin';
    case FullOuter = 'fullOuterJoin';

    public function query(string $ordersId, string $alias): Query
    {
        return match ($this) {
            self::Inner => Query::join($ordersId, '$id', 'customerId', '=', $alias),
            self::Left => Query::leftJoin($ordersId, '$id', 'customerId', '=', $alias),
            self::Right => Query::rightJoin($ordersId, '$id', 'customerId', '=', $alias),
            self::Cross => Query::crossJoin($ordersId, $alias),
            self::FullOuter => Query::fullOuterJoin($ordersId, '$id', 'customerId', '=', $alias),
        };
    }

    /**
     * @param list<Customer> $customers
     * @param list<Order> $orders
     * @return list<Pair>
     */
    public function pairs(array $customers, array $orders): array
    {
        return match ($this) {
            self::Inner => self::matched($customers, $orders),
            self::Left => [...self::matched($customers, $orders), ...self::unmatchedCustomers($customers, $orders)],
            self::Right => [...self::matched($customers, $orders), ...self::unmatchedOrders($customers, $orders)],
            self::Cross => self::cartesian($customers, $orders),
            self::FullOuter => [
                ...self::matched($customers, $orders),
                ...self::unmatchedCustomers($customers, $orders),
                ...self::unmatchedOrders($customers, $orders),
            ],
        };
    }

    /**
     * @param list<Customer> $customers
     * @param list<Order> $orders
     * @return list<Pair>
     */
    private static function matched(array $customers, array $orders): array
    {
        $pairs = [];
        foreach ($customers as $customer) {
            foreach ($orders as $order) {
                if ($order->customerId === $customer->id) {
                    $pairs[] = new Pair($customer, $order);
                }
            }
        }

        return $pairs;
    }

    /**
     * @param list<Customer> $customers
     * @param list<Order> $orders
     * @return list<Pair>
     */
    private static function unmatchedCustomers(array $customers, array $orders): array
    {
        $pairs = [];
        foreach ($customers as $customer) {
            $ordered = \array_filter($orders, static fn (Order $order): bool => $order->customerId === $customer->id);
            if ($ordered === []) {
                $pairs[] = new Pair($customer, null);
            }
        }

        return $pairs;
    }

    /**
     * @param list<Customer> $customers
     * @param list<Order> $orders
     * @return list<Pair>
     */
    private static function unmatchedOrders(array $customers, array $orders): array
    {
        $pairs = [];
        foreach ($orders as $order) {
            $owners = \array_filter($customers, static fn (Customer $customer): bool => $order->customerId === $customer->id);
            if ($owners === []) {
                $pairs[] = new Pair(null, $order);
            }
        }

        return $pairs;
    }

    /**
     * @param list<Customer> $customers
     * @param list<Order> $orders
     * @return list<Pair>
     */
    private static function cartesian(array $customers, array $orders): array
    {
        $pairs = [];
        foreach ($customers as $customer) {
            foreach ($orders as $order) {
                $pairs[] = new Pair($customer, $order);
            }
        }

        return $pairs;
    }
}
