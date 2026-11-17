<?php

namespace Appwrite\Alert\Probe;

use Appwrite\Alert\Probe;
use Utopia\Database\Document;

/**
 * A check the prober already ran, handed to the alerts worker's probe path so it is recorded,
 * alerted on and announced as one the alerts worker ran itself.
 */
final readonly class Reported implements Probe
{
    public function __construct(
        private Check $check,
    ) {
    }

    public function check(Document $monitor, Egress $egress): Check
    {
        return $this->check;
    }
}
