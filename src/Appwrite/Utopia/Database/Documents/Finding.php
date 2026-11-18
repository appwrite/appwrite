<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Database\Document;

/**
 * A finding a review posted on a pull request, and what the people it was posted for made of it.
 */
class Finding extends Document
{
    /**
     * Whether the finding was useful: true when it was fixed or liked, false when it was declined
     * or disliked, null while nobody has said either way. A 👍 or 👎 is the explicit verdict, so
     * reactions decide when they lean one way, and the outcome decides otherwise: resolved (fixed
     * by a later commit, or said fixed in its thread) is useful; dismissed (declined in its thread,
     * or its thread resolved by a maintainer without a change) is not. A dropped finding was never
     * shown to anyone, so it has no verdict.
     */
    public function isUseful(): ?bool
    {
        $status = $this->getAttribute('status', 'posted');
        if ($status === 'dropped') {
            return null;
        }

        $votes = $this->getAttribute('upvotes', 0) - $this->getAttribute('downvotes', 0);
        if ($votes !== 0) {
            return $votes > 0;
        }

        return match ($status) {
            'resolved' => true,
            'dismissed' => false,
            default => null,
        };
    }
}
