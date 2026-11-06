<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Validator\UID;

/**
 * Accepts any ID, plus one keyword the server resolves on the caller's behalf
 * — `current()` for the session a request is authenticated as, `recent()` for
 * the newest one.
 *
 * The parentheses are what make a keyword safe: a stored ID can never contain
 * them, so no resource is able to shadow it — unlike a bare `current`, which
 * is itself a perfectly valid ID.
 */
class KeywordId extends UID
{
    public function __construct(
        private readonly string $keyword,
        int $maxLength = Database::MAX_UID_DEFAULT_LENGTH,
    ) {
        parent::__construct($maxLength);
    }

    public function isValid($value): bool
    {
        return $value === $this->keyword || parent::isValid($value);
    }

    public function getDescription(): string
    {
        return parent::getDescription() . '. Can also be the keyword ' . $this->keyword;
    }
}
