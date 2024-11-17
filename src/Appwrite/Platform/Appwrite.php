<?php

namespace Appwrite\Platform;

use Appwrite\Platform\Modules\Core;
use Utopia\Platform\Platform;
use Appwrite\Platform\Modules\Tokens;

class Appwrite extends Platform
{
    public function __construct()
    {
        parent::__construct(new Core());
        $this->addModule(new Tokens\Module());
    }
}
