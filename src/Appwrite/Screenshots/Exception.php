<?php

namespace Appwrite\Screenshots;

class Exception extends \Exception
{
    /**
     * Statuses the browser service answers with when it refuses the page rather
     * than fails to render it: a navigation it blocked (403) and a page it
     * closed for crossing its memory budget (507).
     */
    private const REFUSED = [403, 507];

    /**
     * Whether capturing this page again gives the same answer.
     *
     * A refusal is about the page itself, so every retry repeats the browser
     * work and fails the same way. A page that failed to render or timed out,
     * and a service that did not answer, may well succeed on another attempt.
     */
    public function isPermanent(): bool
    {
        if (\in_array($this->getCode(), self::REFUSED, true)) {
            return true;
        }

        // The service checks the URL before it opens a page, and answers that
        // refusal with the status it also uses for a page that failed.
        return \str_contains($this->getMessage(), 'is not publicly routable');
    }
}
