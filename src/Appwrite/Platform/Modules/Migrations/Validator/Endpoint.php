<?php

namespace Appwrite\Platform\Modules\Migrations\Validator;

use Appwrite\Network\Allowlist;
use Appwrite\Network\Validator\PublicURL;
use Utopia\System\System;

class Endpoint extends PublicURL
{
    public function __construct()
    {
        parent::__construct(Allowlist::parse(System::getEnv('_APP_MIGRATIONS_ALLOWED_HOSTS', '')));
    }

    public function getDescription(): string
    {
        return 'Value must be an http or https URL of a public host.';
    }
}
