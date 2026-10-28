<?php

use Appwrite\Bus\Listeners\Connections;
use Appwrite\Bus\Listeners\ExecutionCancelledCleanup;
use Appwrite\Bus\Listeners\Log;
use Appwrite\Bus\Listeners\Mails;
use Appwrite\Bus\Listeners\Usage;

return [
    new Connections(),
    new ExecutionCancelledCleanup(),
    new Log(),
    new Mails(),
    new Usage(),
];
