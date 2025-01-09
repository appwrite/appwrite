<?php

/**
 * Same as ./runtimes.php, but without deprecated runtimes
 */

use Appwrite\Runtimes\Runtimes;

return (new Runtimes('v4'))->getAll(deprecated: false);
