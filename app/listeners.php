<?php

use Appwrite\Bus\Listeners\Domains;
use Appwrite\Bus\Listeners\ExecutionCancelledCleanup;
use Appwrite\Bus\Listeners\Log;
use Appwrite\Bus\Listeners\Mails;
use Appwrite\Bus\Listeners\Usage;

return [
    new Domains(),
    new ExecutionCancelledCleanup(),
    new Log(),
    new Mails(),
    new Usage(),
];
