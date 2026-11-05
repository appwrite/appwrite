<?php

namespace Utopia\Agents;

/**
 * Whether and which tool the model must call.
 */
final readonly class ToolChoice
{
    public const string AUTO = 'auto';

    public const string NONE = 'none';

    public const string REQUIRED = 'required';

    public const string TOOL = 'tool';

    private function __construct(
        public string $type,
        public ?string $name = null,
    ) {
    }

    /** The model decides. */
    public static function auto(): self
    {
        return new self(self::AUTO);
    }

    /** The model must not call tools. */
    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** The model must call at least one tool. */
    public static function required(): self
    {
        return new self(self::REQUIRED);
    }

    /** The model must call the named tool. */
    public static function tool(string $name): self
    {
        return new self(self::TOOL, $name);
    }
}
