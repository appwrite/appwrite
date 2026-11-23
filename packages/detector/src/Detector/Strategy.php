<?php

namespace Utopia\Detector\Detector;

class Strategy
{
    public const FILEMATCH = 'filematch';

    public const EXTENSION = 'extension';

    public const LANGUAGES = 'languages';

    private string $value;

    public function __construct(string $value)
    {
        if (! in_array($value, [self::FILEMATCH, self::EXTENSION, self::LANGUAGES])) {
            throw new \Exception("Invalid strategy: {$value}");
        }
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
