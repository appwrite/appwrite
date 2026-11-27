<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detector;

class Strategy
{
    public const FILEMATCH = 'filematch';

    public const EXTENSION = 'extension';

    public const LANGUAGES = 'languages';

    private string $value;

    public function __construct(string $value)
    {
    }

    public function getValue(): string
    {
    }
}
