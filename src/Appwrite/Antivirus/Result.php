<?php

namespace Appwrite\Antivirus;

class Result
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';

    public function __construct(
        public readonly string $verdict,
        public readonly ?string $signature = null,
        public readonly int $size = 0,
        public readonly string $md5 = '',
        public readonly string $sha1 = '',
        public readonly string $sha256 = '',
        public readonly int $durationUs = 0,
    ) {
    }

    public function isInfected(): bool
    {
        return $this->verdict === self::INFECTED;
    }

    public function isClean(): bool
    {
        return $this->verdict === self::CLEAN;
    }
}
