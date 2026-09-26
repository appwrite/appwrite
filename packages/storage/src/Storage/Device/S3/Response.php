<?php

declare(strict_types=1);

namespace Utopia\Storage\Device\S3;

final readonly class Response
{
    /**
     * @param  array<string, string>  $headers
     * @param  string|array<mixed>  $body  Raw body, or the decoded XML document
     */
    public function __construct(
        public int $code,
        public array $headers,
        public string|array $body,
    ) {}
}
