<?php

declare(strict_types=1);

namespace Utopia\Cdn\Certificates;

/**
 * A DNS record the domain owner has to publish before the certificate
 * authority will accept that they control the domain.
 */
final readonly class Challenge
{
    /**
     * @param string $type Provider-specific challenge type, for example Fastly's `managed-dns`.
     * @param string $recordType DNS record type to create, for example `CNAME`.
     * @param string $recordName Fully qualified name of the record to create.
     * @param list<string> $values Values the record has to hold; several values are alternatives.
     */
    public function __construct(
        public string $type,
        public string $recordType,
        public string $recordName,
        public array $values,
    ) {}
}
