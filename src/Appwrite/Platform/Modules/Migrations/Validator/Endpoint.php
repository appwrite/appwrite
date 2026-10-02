<?php

namespace Appwrite\Platform\Modules\Migrations\Validator;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Network\Validator\PublicURL;
use Utopia\System\System;
use Utopia\Validator\Allowlist;
use Utopia\Validator\Subnet;

class Endpoint extends PublicURL
{
    public function __construct()
    {
        parent::__construct(self::allowlist(System::getEnv('_APP_MIGRATIONS_ALLOWED_HOSTS', '')));
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

    private static function allowlist(string $value): Allowlist
    {
        $hostnames = [];
        $subnets = [];

        foreach (\explode(',', $value) as $entry) {
            $entry = \trim($entry);

            if ($entry === '') {
                continue;
            }

            if (\str_contains($entry, '/') || \filter_var(\trim($entry, '[]'), FILTER_VALIDATE_IP) !== false) {
                $subnet = self::subnet(\str_contains($entry, '/') ? $entry : \trim($entry, '[]'));

                if ($subnet !== null) {
                    $subnets[] = $subnet;
                }

                continue;
            }

            if (
                \filter_var($entry, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                && !PublicHostname::isNumericAddress($entry)
            ) {
                $hostnames[] = $entry;
            }
        }

        return new Allowlist($hostnames, $subnets);
    }

    private static function subnet(string $range): ?Subnet
    {
        try {
            return new Subnet($range);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
