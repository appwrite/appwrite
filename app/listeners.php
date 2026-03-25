<?php

use Appwrite\Bus\Listeners\Log;
use Appwrite\Bus\Listeners\Usage;

return [
    new Log(),
    new Usage(),
];
