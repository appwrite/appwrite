<?php

namespace Appwrite\Reviews;

/**
 * How hard the review service looks at a repository's pull requests, and what a review costs.
 * Basic runs cheaper models that only read the code, normal the default ones, expert stronger
 * models with subagents. The service (appwrite/hansi) maps each to its models.
 */
enum Strength: string
{
    case Basic = 'basic';
    case Normal = 'normal';
    case Expert = 'expert';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $strength): string => $strength->value, self::cases());
    }
}
