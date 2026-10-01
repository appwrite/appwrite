<?php

namespace Appwrite\Platform\Modules\Migrations\Validator;

use Appwrite\Extend\Exception;
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

    /**
     * @return array<string>
     *
     * @throws Exception
     */
    public function resolve(string $url): array
    {
        if (!$this->isValid($url)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `endpoint`: ' . $this->getDescription());
        }

        return $this->getResolve();
    }
}
