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

        $this->addAction('installerView', View::class);
        $this->addAction('installerStatus', Status::class);
        $this->addAction('installerValidate', Validate::class);
        $this->addAction('installerComplete', Complete::class);
        $this->addAction('installerShutdown', Shutdown::class);
        $this->addAction('installerReset', Reset::class);
        $this->addAction('installerInstall', Install::class);
        $this->addAction('installerCertificateGet', CertificateGet::class);
    }
}
