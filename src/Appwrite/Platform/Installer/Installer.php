<?php

namespace Appwrite\Platform\Installer;

use Utopia\Platform\Platform;

class Installer extends Platform
{
    public function __construct()
    {
        parent::__construct(new Module());
    }
}
