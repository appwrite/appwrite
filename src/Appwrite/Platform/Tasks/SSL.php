<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\Hostname;
use Utopia\Platform\Action;
use Utopia\System\System;

class SSL extends Action
{
    public static function getName(): string
    {
        return 'ssl';
    }

    public function __construct()
    {
        $this
            ->desc('Validate server certificates')
            ->param('domain', System::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain to generate certificate for. If empty, main domain will be used.', true)
            ->param('skip-check', true, new Boolean(true), 'If DNS and renew check should be skipped. Defaults to true, and when true, all jobs will result in certificate generation attempt.', true)
            ->inject('queueForCertificates')
            ->callback(fn (string $domain, bool|string $skipCheck, Certificate $queueForCertificates) => $this->action($domain, $skipCheck, $queueForCertificates));
    }

    public function action(string $domain, bool|string $skipCheck, Certificate $queueForCertificates): void
    {
        $skipCheck = \strval($skipCheck) === 'true';

        Console::success('Scheduling a job to issue a TLS certificate for domain: ' . $domain);

        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $domain
            ]))
            ->setSkipRenewCheck($skipCheck)
            ->trigger();
    }
}
