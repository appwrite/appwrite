<?php

namespace Appwrite\Platform\Installer\Services;

use Appwrite\Platform\Installer\Http\Installer\Certificate\Get as CertificateGet;
use Appwrite\Platform\Installer\Http\Installer\Complete;
use Appwrite\Platform\Installer\Http\Installer\Install;
use Appwrite\Platform\Installer\Http\Installer\Reset;
use Appwrite\Platform\Installer\Http\Installer\Shutdown;
use Appwrite\Platform\Installer\Http\Installer\Status;
use Appwrite\Platform\Installer\Http\Installer\Validate;
use Appwrite\Platform\Installer\Http\Installer\View;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(View::getName(), View::class);
        $this->addAction(Status::getName(), Status::class);
        $this->addAction(Validate::getName(), Validate::class);
        $this->addAction(Complete::getName(), Complete::class);
        $this->addAction(Shutdown::getName(), Shutdown::class);
        $this->addAction(Reset::getName(), Reset::class);
        $this->addAction(Install::getName(), Install::class);
        $this->addAction(CertificateGet::getName(), CertificateGet::class);
    }
}
