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
