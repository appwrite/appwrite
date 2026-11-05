<?php

namespace Appwrite\SMTP;

/**
 * Decides whether a failed send is acknowledged rather than retried.
 */
final class Refusal
{
    /**
     * True only for a project's own SMTP server refusing for good; Appwrite's own provider keeps its retries.
     *
     * @param array<string, mixed> $result one entry of the adapter's send results
     */
    public static function isFinal(bool $projectSmtp, array $result): bool
    {
        return $projectSmtp && ($result['permanent'] ?? false) === true;
    }
}
