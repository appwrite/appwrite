<?php

use Appwrite\Bus\Listeners\Functions;
use Appwrite\Bus\Listeners\Log;
use Appwrite\Bus\Listeners\Mails;
use Appwrite\Bus\Listeners\Realtime;
use Appwrite\Bus\Listeners\Usage;
use Appwrite\Bus\Listeners\Webhook;

return [
    new Log(),
    new Mails(),
    new Realtime(),
    new Functions(),
    new Webhook(),
    new Usage(),
];
