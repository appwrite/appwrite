<?php

namespace Appwrite\Platform\Installer\Services;

use Appwrite\Platform\Installer\Http\Installer\Complete;
use Appwrite\Platform\Installer\Http\Installer\Error;
use Appwrite\Platform\Installer\Http\Installer\Install;
use Appwrite\Platform\Installer\Http\Installer\Status;
use Appwrite\Platform\Installer\Http\Installer\Validate;
use Appwrite\Platform\Installer\Http\Installer\View;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(View::getName(), new View());
        $this->addAction(Status::getName(), new Status());
        $this->addAction(Validate::getName(), new Validate());
        $this->addAction(Complete::getName(), new Complete());
        $this->addAction(Install::getName(), new Install());
        $this->addAction(Error::getName(), new Error());
    }
}
