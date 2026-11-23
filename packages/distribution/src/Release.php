<?php

declare(strict_types=1);

namespace Utopia\Distribution;

use Utopia\Distribution\Exception\Invalid;

final readonly class Release
{
    public const string TRACK_TESTFLIGHT = 'testflight';

    public const string TRACK_TESTFLIGHT_EXTERNAL = 'testflight-external';

    public const string TRACK_PRODUCTION = 'production';

    public const string TRACK_INTERNAL = 'internal';

    public const string TRACK_ALPHA = 'alpha';

    public const string TRACK_BETA = 'beta';

    /**
     * @param array<string, string> $notes locale => text
     * @param list<string> $groups
     */
    public function __construct(
        public string $identifier,
        public string $track,
        public string $version,
        public string $build,
        public float $fraction = 1.0,
        public array $notes = [],
        public bool $automatic = true,
        public array $groups = [],
        public ?bool $encryption = null,
    ) {
        if ($track === '') {
            throw new Invalid('Release track must not be empty');
        }

        if (is_nan($fraction) || $fraction <= 0.0 || $fraction > 1.0) {
            throw new Invalid('Release fraction must be greater than 0 and at most 1');
        }
    }
}
