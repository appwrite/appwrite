<?php

namespace Appwrite\Platform\Modules\VCS\Services;

use Appwrite\Platform\Modules\VCS\Http\Installations\Get as GetInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\Delete as DeleteInstallation;
use Appwrite\Platform\Modules\VCS\Http\Installations\XList as ListInstallations;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Installations
        $this->addAction(GetInstallation::getName(), new GetInstallation());
        $this->addAction(ListInstallations::getName(), new ListInstallations());
        $this->addAction(DeleteInstallation::getName(), new DeleteInstallation());
    }
}