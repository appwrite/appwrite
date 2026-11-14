<?php

namespace Appwrite\Alert\Probe;

use Appwrite\Alert\Probe;
use Utopia\Database\Document;

/**
 * Opens a TCP connection to the monitor's host and port and closes it at once,
 * without sending a byte.
 */
final readonly class TCP implements Probe
{
    public function check(Document $monitor, Egress $egress): Check
    {
        $start = \hrtime(true);
        $deadline = \microtime(true) + (int) $monitor->getAttribute('timeout', 10);

        try {
            Connection::open((string) $monitor->getAttribute('host'), (int) $monitor->getAttribute('port'), false, true, $egress, $deadline)->close();
        } catch (Failure $failure) {
            return new Check($failure->result, (int) \round((\hrtime(true) - $start) / 1e6));
        }

        return new Check(Result::Ok, (int) \round((\hrtime(true) - $start) / 1e6));
    }
}
