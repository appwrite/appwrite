<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

/**
 * A stream source or mirror definition. The same wire shape is used for both
 * the stream `mirror` field and each entry of the `sources` array.
 */
final class StreamSource
{
    /**
     * @param list<SubjectTransform>|null $subjectTransforms
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $optStartSeq = null,
        public readonly ?string $optStartTime = null,
        public readonly ?string $filterSubject = null,
        public readonly ?array $subjectTransforms = null,
        public readonly ?ExternalStream $external = null,
    ) {}

    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if ($this->optStartSeq !== null) {
            $data['opt_start_seq'] = $this->optStartSeq;
        }
        if ($this->optStartTime !== null) {
            $data['opt_start_time'] = $this->optStartTime;
        }
        if ($this->filterSubject !== null) {
            $data['filter_subject'] = $this->filterSubject;
        }
        if ($this->subjectTransforms !== null) {
            $data['subject_transforms'] = array_map(
                static fn(SubjectTransform $t): array => $t->toArray(),
                $this->subjectTransforms,
            );
        }
        if ($this->external instanceof \Utopia\NATS\JetStream\ExternalStream) {
            $data['external'] = $this->external->toArray();
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            optStartSeq: $data['opt_start_seq'] ?? null,
            optStartTime: $data['opt_start_time'] ?? null,
            filterSubject: $data['filter_subject'] ?? null,
            subjectTransforms: isset($data['subject_transforms'])
                ? array_map(SubjectTransform::fromArray(...), $data['subject_transforms'])
                : null,
            external: isset($data['external']) ? ExternalStream::fromArray($data['external']) : null,
        );
    }
}
