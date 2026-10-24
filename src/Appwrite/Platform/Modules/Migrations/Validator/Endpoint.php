<?php

namespace Appwrite\Platform\Modules\Migrations\Validator;

use Appwrite\Extend\Exception;
use Appwrite\Network\Allowlist;
use Appwrite\Network\Validator\PublicHostname;
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

    /**
     * An address to connect to a source database host on. Connect to the
     * returned address, not the host, so DNS cannot answer differently at
     * connect time.
     *
     * @throws Exception
     */
    public function address(string $host): string
    {
        try {
            return (new PublicHostname(Allowlist::parse(System::getEnv('_APP_MIGRATIONS_ALLOWED_HOSTS', ''))))->address($host);
        } catch (\InvalidArgumentException $exception) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `databaseHost`: ' . $exception->getMessage());
        }
    }
}
