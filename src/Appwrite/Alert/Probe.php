<?php

namespace Appwrite\Alert;

use Appwrite\Alert\Probe\Check;
use Appwrite\Alert\Probe\Egress;
use Utopia\Database\Document;

/**
 * Checks the target a probe monitor watches, once. Each monitor type that
 * probes registers its implementation under `probe` in `app/config/alerts.php`.
 * Runs inside a coroutine, in the alerts worker.
 */
interface Probe
{
    public function check(Document $monitor, Egress $egress): Check;
}
