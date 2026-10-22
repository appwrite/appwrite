<?php

namespace Appwrite\Platform\Modules\Compute\Validator;

use Utopia\Validator\Text;

/**
 * A directory inside a repository: the one a function or site builds from,
 * or the one a template ships. It is appended to a checkout's path, on the
 * build host as well as on the build sidecar, so it must stay inside that
 * checkout: no ".." segment, no control byte. A leading "./" or "/" is
 * harmless (the checkout path is prefixed regardless) and empty means the
 * repository root.
 */
class RootDirectory extends Text
{
    public function __construct(int $length = 128)
    {
        parent::__construct($length, 0);
    }

    public function getDescription(): string
    {
        $description = 'Value must be a path inside the repository, without ".." segments or control characters';

        if ($this->length !== 0) {
            $description .= ', and be at most ' . $this->length . ' chars';
        }

        return $description . '.';
    }

    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && self::isConfined($value);
    }

    /**
     * Whether a path appended to a directory can only name something inside
     * that directory. Stored values predate the endpoint rule, so the build
     * worker checks them with this before touching the host.
     */
    public static function isConfined(string $path): bool
    {
        $length = \strlen($path);
        for ($i = 0; $i < $length; $i++) {
            $byte = \ord($path[$i]);
            if ($byte < 0x20 || $byte === 0x7F) {
                return false;
            }
        }

        foreach (\explode('/', \str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
