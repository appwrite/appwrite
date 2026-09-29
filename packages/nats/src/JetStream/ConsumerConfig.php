<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class ConsumerConfig
{
    /**
     * @param float|null $ackWait Ack wait in seconds
     * @param float|null $inactiveThreshold Inactive threshold in seconds
     * @param float|null $idleHeartbeat Idle heartbeat interval in seconds (push consumers)
     * @param list<string>|null $filterSubjects
     * @param array<string, string>|null $metadata Arbitrary key-value metadata (ADR-33)
     * @param list<float>|null $backoff Redelivery delays in seconds, one per attempt
     *        (the last entry repeats). Server rules, not sanitized here: when set
     *        together with $ackWait the first entry must equal $ackWait, and
     *        $maxDeliver must exceed the number of entries.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $durableName = null,
        public readonly ?string $description = null,
        public readonly DeliverPolicy $deliverPolicy = DeliverPolicy::All,
        public readonly AckPolicy $ackPolicy = AckPolicy::Explicit,
        public readonly ?float $ackWait = null,
        public readonly ?int $maxDeliver = null,
        public readonly ?string $filterSubject = null,
        public readonly ?array $filterSubjects = null,
        public readonly ReplayPolicy $replayPolicy = ReplayPolicy::Instant,
        public readonly ?int $maxWaiting = null,
        public readonly ?int $maxAckPending = null,
        public readonly ?float $inactiveThreshold = null,
        public readonly ?int $optStartSeq = null,
        public readonly ?string $optStartTime = null,
        public readonly ?int $maxBatch = null,
        public readonly ?int $maxBytes = null,
        public readonly bool $memStorage = false,
        public readonly ?int $numReplicas = null,
        public readonly ?string $deliverSubject = null,
        public readonly ?string $deliverGroup = null,
        public readonly bool $flowControl = false,
        public readonly ?float $idleHeartbeat = null,
        public readonly ?array $metadata = null,
        public readonly ?array $backoff = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'deliver_policy' => $this->deliverPolicy->value,
            'ack_policy' => $this->ackPolicy->value,
            'replay_policy' => $this->replayPolicy->value,
        ];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->durableName !== null) {
            $data['durable_name'] = $this->durableName;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->ackWait !== null) {
            $data['ack_wait'] = StreamConfig::secondsToNanos($this->ackWait);
        }
        if ($this->maxDeliver !== null) {
            $data['max_deliver'] = $this->maxDeliver;
        }
        if ($this->filterSubject !== null) {
            $data['filter_subject'] = $this->filterSubject;
        }
        if ($this->filterSubjects !== null) {
            $data['filter_subjects'] = $this->filterSubjects;
        }
        if ($this->maxWaiting !== null) {
            $data['max_waiting'] = $this->maxWaiting;
        }
        if ($this->maxAckPending !== null) {
            $data['max_ack_pending'] = $this->maxAckPending;
        }
        if ($this->inactiveThreshold !== null) {
            $data['inactive_threshold'] = StreamConfig::secondsToNanos($this->inactiveThreshold);
        }
        if ($this->optStartSeq !== null) {
            $data['opt_start_seq'] = $this->optStartSeq;
        }
        if ($this->optStartTime !== null) {
            $data['opt_start_time'] = $this->optStartTime;
        }
        if ($this->maxBatch !== null) {
            $data['max_batch'] = $this->maxBatch;
        }
        if ($this->maxBytes !== null) {
            $data['max_bytes'] = $this->maxBytes;
        }
        if ($this->memStorage) {
            $data['mem_storage'] = true;
        }
        if ($this->numReplicas !== null) {
            $data['num_replicas'] = $this->numReplicas;
        }
        if ($this->deliverSubject !== null) {
            $data['deliver_subject'] = $this->deliverSubject;
        }
        if ($this->deliverGroup !== null) {
            $data['deliver_group'] = $this->deliverGroup;
        }
        if ($this->flowControl) {
            $data['flow_control'] = true;
        }
        if ($this->idleHeartbeat !== null) {
            $data['idle_heartbeat'] = StreamConfig::secondsToNanos($this->idleHeartbeat);
        }
        if ($this->metadata !== null) {
            $data['metadata'] = $this->metadata;
        }
        if ($this->backoff !== null) {
            $data['backoff'] = array_map(StreamConfig::secondsToNanos(...), $this->backoff);
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            durableName: $data['durable_name'] ?? null,
            description: $data['description'] ?? null,
            deliverPolicy: DeliverPolicy::tryFrom($data['deliver_policy'] ?? '') ?? DeliverPolicy::All,
            ackPolicy: AckPolicy::tryFrom($data['ack_policy'] ?? '') ?? AckPolicy::Explicit,
            ackWait: isset($data['ack_wait']) ? StreamConfig::nanosToSeconds($data['ack_wait']) : null,
            maxDeliver: $data['max_deliver'] ?? null,
            filterSubject: $data['filter_subject'] ?? null,
            filterSubjects: $data['filter_subjects'] ?? null,
            replayPolicy: ReplayPolicy::tryFrom($data['replay_policy'] ?? '') ?? ReplayPolicy::Instant,
            maxWaiting: $data['max_waiting'] ?? null,
            maxAckPending: $data['max_ack_pending'] ?? null,
            inactiveThreshold: isset($data['inactive_threshold']) ? StreamConfig::nanosToSeconds($data['inactive_threshold']) : null,
            optStartSeq: $data['opt_start_seq'] ?? null,
            optStartTime: $data['opt_start_time'] ?? null,
            maxBatch: $data['max_batch'] ?? null,
            maxBytes: $data['max_bytes'] ?? null,
            memStorage: $data['mem_storage'] ?? false,
            numReplicas: $data['num_replicas'] ?? null,
            deliverSubject: $data['deliver_subject'] ?? null,
            deliverGroup: $data['deliver_group'] ?? null,
            flowControl: $data['flow_control'] ?? false,
            idleHeartbeat: isset($data['idle_heartbeat']) ? StreamConfig::nanosToSeconds($data['idle_heartbeat']) : null,
            metadata: $data['metadata'] ?? null,
            backoff: isset($data['backoff']) ? array_map(StreamConfig::nanosToSeconds(...), $data['backoff']) : null,
        );
    }
}
