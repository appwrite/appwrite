<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Appwrite\Event\Certificate;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Validator\Hostname;

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
            ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain to generate certificate for. If empty, main domain will be used.', true)
            ->callback($this->action);
    }

    public function action(string $domain): void
    {
        Console::success('Scheduling a job to issue a TLS certificate for domain: ' . $domain);

        (new Certificate())
            ->setDomain(new Document([
                'domain' => $domain
            ]))
            ->setSkipRenewCheck(true)
            ->trigger();
    }
}
