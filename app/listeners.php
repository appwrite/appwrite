<?php

use Appwrite\Bus\Listeners\Mails;
use Appwrite\Bus\Listeners\Usage;

return [
    new Mails(),
    new Usage(),
];
