<?php

declare(strict_types=1);

namespace Utopia\Distribution;

use Utopia\Distribution\Exception\Invalid;
use Utopia\Storage\Device;

final readonly class Listing
{
    public const string FIELD_NAME = 'name';

    public const string FIELD_SUBTITLE = 'subtitle';

    public const string FIELD_SHORT_DESCRIPTION = 'shortDescription';

    public const string FIELD_DESCRIPTION = 'description';

    public const string FIELD_KEYWORDS = 'keywords';

    public const string FIELD_PROMOTIONAL_TEXT = 'promotionalText';

    public const string FIELD_SUPPORT_URL = 'supportUrl';

    public const string FIELD_MARKETING_URL = 'marketingUrl';

    public const string FIELD_PRIVACY_URL = 'privacyUrl';

    private const array FIELDS = [
        self::FIELD_NAME,
        self::FIELD_SUBTITLE,
        self::FIELD_SHORT_DESCRIPTION,
        self::FIELD_DESCRIPTION,
        self::FIELD_KEYWORDS,
        self::FIELD_PROMOTIONAL_TEXT,
        self::FIELD_SUPPORT_URL,
        self::FIELD_MARKETING_URL,
        self::FIELD_PRIVACY_URL,
    ];

    /**
     * @param array<string, string> $fields FIELD_* => text
     * @param list<array{set: string, path: string, size: int, width: int, height: int, digest: string}> $screenshots
     */
    public function __construct(
        public string $identifier,
        public string $locale,
        public array $fields,
        public array $screenshots = [],
        public ?Device $device = null,
    ) {
        foreach (array_keys($fields) as $field) {
            if (!\in_array($field, self::FIELDS, true)) {
                throw new Invalid("Listing field \"{$field}\" is not supported");
            }
        }

        if ($screenshots !== [] && !$device instanceof Device) {
            throw new Invalid('Listing screenshots need a device to read them from');
        }
    }
}
