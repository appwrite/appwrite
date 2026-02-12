<?php

namespace Appwrite\Platform;

use Appwrite\Platform\Modules\Account;
use Appwrite\Platform\Modules\Avatars;
use Appwrite\Platform\Modules\Console;
use Appwrite\Platform\Modules\Core;
use Appwrite\Platform\Modules\Databases;
use Appwrite\Platform\Modules\Functions;
use Appwrite\Platform\Modules\Health;
use Appwrite\Platform\Modules\Logs;
use Appwrite\Platform\Modules\Projects;
use Appwrite\Platform\Modules\Proxy;
use Appwrite\Platform\Modules\Sites;
use Appwrite\Platform\Modules\Storage;
use Appwrite\Platform\Modules\Tokens;
use Appwrite\Platform\Modules\VCS;
use Utopia\Platform\Platform;

class Appwrite extends Platform
{
    public function __construct()
    {
        parent::__construct(new Core());
        $this->addModule(new Account\Module());
        $this->addModule(new Avatars\Module());
        $this->addModule(new Databases\Module());
        $this->addModule(new Projects\Module());
        $this->addModule(new Functions\Module());
        $this->addModule(new Health\Module());
        $this->addModule(new Sites\Module());
        $this->addModule(new Console\Module());
        $this->addModule(new Proxy\Module());
        $this->addModule(new Tokens\Module());
        $this->addModule(new Storage\Module());
        $this->addModule(new VCS\Module());
        $this->addModule(new Logs\Module());
    }
}
