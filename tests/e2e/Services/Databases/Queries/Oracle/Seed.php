<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

/**
 * Customers, orders and payments where hidden rows are readable only by {@see self::HIDDEN_USER}:
 * a hidden customer with a readable order, a readable customer whose only order is hidden, and
 * hidden orders that share a label with readable ones or carry a label of their own.
 */
final readonly class Seed
{
    public const string HIDDEN_USER = 'join-aggregate-hidden';

    /**
     * Splits the label groups differently for callers with and without the hidden user's role.
     */
    public const int GROUP_THRESHOLD = 1000;

    /**
     * @param list<Customer> $customers
     * @param list<Order> $orders
     * @param list<Payment> $payments
     */
    public function __construct(
        public array $customers,
        public array $orders,
        public array $payments,
    ) {
    }

    public static function create(): self
    {
        return new self(
            customers: [
                new Customer('alice', 'Alice', false),
                new Customer('bob', 'Bob', false),
                new Customer('carol', 'Carol', false),
                new Customer('dave', 'Dave', false),
                new Customer('frank', 'Frank', false),
                new Customer('eve', 'Eve', true),
            ],
            orders: [
                new Order('o200', 'alice', 200, 'paid', 1, false),
                new Order('o313', 'alice', 313, 'open', 3, false),
                new Order('o424', 'bob', 424, 'paid', 5, false),
                new Order('o100', 'dave', 100, 'open', 7, false),
                new Order('o700', null, 700, 'void', 1, false),
                new Order('o900', 'eve', 900, 'paid', 3, false),
                new Order('o8686', 'alice', 8686, 'paid', 8, true),
                new Order('o5151', null, 5151, 'refund', 16, true),
                new Order('o6060', 'frank', 6060, 'paid', 32, true),
            ],
            payments: [
                new Payment('p200', 'o200', 150),
                new Payment('p424', 'o424', 400),
                new Payment('p8686', 'o8686', 8000),
            ],
        );
    }

    /**
     * What a caller without the hidden user's role reads directly. Payments live in a collection
     * whose collection-level read grants every row, so none of them is hidden.
     */
    public function readable(): self
    {
        return new self(
            \array_values(\array_filter($this->customers, static fn (Customer $customer): bool => !$customer->hidden)),
            \array_values(\array_filter($this->orders, static fn (Order $order): bool => !$order->hidden)),
            $this->payments,
        );
    }

    public function visibleTo(bool $restricted): self
    {
        return $restricted ? $this->readable() : $this;
    }

    /**
     * @return list<int>
     */
    public function amounts(): array
    {
        return \array_map(static fn (Order $order): int => $order->amount, $this->orders);
    }

    /**
     * @return list<int>
     */
    public function flags(): array
    {
        return \array_map(static fn (Order $order): int => $order->flags, $this->orders);
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return \array_map(static fn (Order $order): string => $order->label, $this->orders);
    }

    /**
     * Payments reached by `customers JOIN orders JOIN payments ON orders.$id = payments.orderId`.
     *
     * @return list<Payment>
     */
    public function joinedPayments(): array
    {
        $joined = [];
        foreach (Join::Inner->pairs($this->customers, $this->orders) as $pair) {
            if ($pair->order !== null) {
                $joined[$pair->order->id] = true;
            }
        }

        return \array_values(\array_filter($this->payments, static fn (Payment $payment): bool => isset($joined[$payment->orderId])));
    }

    public function joinedOrderTotal(): int
    {
        return \array_sum(\array_map(fn (Payment $payment): int => $this->order($payment->orderId)->amount, $this->joinedPayments()));
    }

    public function joinedPaymentTotal(): int
    {
        return \array_sum(\array_map(static fn (Payment $payment): int => $payment->amount, $this->joinedPayments()));
    }

    public function order(string $id): Order
    {
        foreach ($this->orders as $order) {
            if ($order->id === $id) {
                return $order;
            }
        }

        throw new \OutOfBoundsException("Order '{$id}' is not seeded");
    }
}
