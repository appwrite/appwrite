<?php

namespace Appwrite\Vcs;

/**
 * What {@see DeliveryClaims::take()} gave an attempt for one piece of a
 * delivery's work.
 */
readonly class DeliveryClaim
{
    /** This attempt holds the work and does it */
    public const string TAKEN = 'taken';

    /** Another attempt is still on the work; the delivery has to come back */
    public const string BUSY = 'busy';

    /** An attempt finished the work; nothing is left to do */
    public const string DONE = 'done';

    public function __construct(
        public string $status,
        public string $work,
        /** The claim document this attempt holds, when it holds one */
        public string $id = '',
        /**
         * When the work began, if this attempt took it over from one that
         * stopped without finishing or giving it back. That attempt may have
         * deployed already.
         */
        public ?string $resumedFrom = null,
    ) {
    }
}
