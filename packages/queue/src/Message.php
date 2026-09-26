<?php

namespace Utopia\Queue;

class Message
{
    protected string $pid;
    protected string $queue;
    protected int $timestamp;
    protected array $payload;
    protected int $attempts = 0;
    protected ?int $sequence = null;
    protected bool $terminal = false;

    public function __construct(array $array = [])
    {
        if ($array === []) {
            return;
        }

        $this->pid = $array['pid'];
        $this->queue = $array['queue'];
        $this->timestamp = $array['timestamp'];
        $this->payload = $array['payload'] ?? [];
        $this->attempts = $array['attempts'] ?? 0;
        $this->sequence = $array['sequence'] ?? null;
    }

    private ?string $receipt = null;

    public function getReceipt(): ?string
    {
        return $this->receipt;
    }

    public function setReceipt(string $receipt): self
    {
        $this->receipt = $receipt;
        return $this;
    }

    public function setPid(string $pid): self
    {
        $this->pid = $pid;

        return $this;
    }

    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getPid(): string
    {
        return $this->pid;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Times this message has been requeued after a failed or stranded run.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    /**
     * The broker's own position for this message, where it has one.
     *
     * A stronger deduplication key than the pid for a handler that needs one.
     * The pid identifies the logical message and is stable across every
     * redelivery, which is what makes it the right key for "have I already done
     * this work"; the sequence identifies the stored copy, so it distinguishes
     * one delivery of a message from another. Null on brokers with no such
     * notion, so a handler must treat it as optional.
     */
    public function getSequence(): ?int
    {
        return $this->sequence;
    }

    public function setSequence(?int $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    /**
     * Declare that this message must not be delivered again.
     *
     * The verdict a handler reaches about its own failure: this payload will
     * fail the same way on every attempt, so spending the redelivery budget on
     * it buys nothing and — on a broker that counts an unacked message against a
     * ceiling, as JetStream does — costs a delivery slot for the whole of it.
     * What it costs to ignore decides what a broker does with it.
     * {@see Broker\Nats::reject()} dead-letters a terminal message at once
     * instead of scheduling the next attempt, because every further attempt
     * would hold an ack slot for the length of its backoff.
     * {@see Broker\Redis::reject()} has no such ceiling to protect and leaves
     * the message on the failed list with every other rejection: nothing re-runs
     * it there either, and that list is the only one an operator can recover
     * from. A broker with no notion of the distinction rejects it the way it
     * always has.
     *
     * Throwing {@see PermanentFailure} sets this and is the shorter route. This
     * is here for a handler that cannot: the exception type belongs to a library,
     * or the classification happens somewhere that only has the message. It is
     * read when the message is rejected, so set it before the handler gives up.
     *
     * Per delivery, not part of the envelope: a redelivered copy of the same
     * payload arrives with a fresh verdict, because the reason it failed may
     * have been fixed in between.
     */
    public function terminal(bool $terminal = true): self
    {
        $this->terminal = $terminal;

        return $this;
    }

    /** Whether the handler declared this failure permanent. {@see self::terminal()} */
    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    public function asArray(): array
    {
        return [
            'pid' => $this->pid,
            'queue' => $this->queue,
            'timestamp' => $this->timestamp,
            'payload' => $this->payload ?? null,
            'attempts' => $this->attempts,
            'sequence' => $this->sequence,
        ];
    }
}
