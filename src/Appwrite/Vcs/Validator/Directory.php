<?php

namespace Appwrite\Vcs\Validator;

use Utopia\Validator\Validator;

/**
 * A directory inside a repository checkout, relative to its root. A leading
 * `/` or `./` anchors at the root; a `..` segment would leave the checkout.
 */
class Directory extends Validator
{
    public function __construct(private readonly int $length = 128)
    {
    }

    public function getDescription(): string
    {
        return 'Value must be a directory inside the repository, at most ' . $this->length . ' chars long, without `..` or `.git` segments or control characters.';
    }

    public function isValid(mixed $value): bool
    {
        if (!\is_string($value) || \mb_strlen($value) > $this->length) {
            return false;
        }

        if (\strpbrk($value, \implode('', \array_map(\chr(...), [...\range(0, 31), 127]))) !== false) {
            return false;
        }

        foreach (\explode('/', $value) as $segment) {
            if ($segment === '..' || \strtolower($segment) === '.git') {
                return false;
            }
        }

        return true;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
