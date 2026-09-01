<?php

declare(strict_types=1);

namespace Appwrite\Smtp;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS as ValidatorDNS;
use Utopia\Database\Document;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\System\System;

final readonly class DomainVerification
{
    public function __construct(private string $dnsValidatorClass = ValidatorDNS::class)
    {
    }

    public function verify(Document $rule, ?Log $log = null): void
    {
        $dnsEnv = System::getEnv('_APP_DNS', '8.8.8.8');
        $servers = array_map('trim', explode(',', $dnsEnv));
        $dnsServers = array_filter($servers, fn ($server) => ! empty($server));

        $domain = new Domain($rule->getAttribute('domain', ''));
        if (empty($domain->get()) || ! $domain->isKnown() || $domain->isTest()) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain does not resolve to a known public apex domain.');
        }

        $mxTarget = trim(System::getEnv('_APP_DOMAIN_TARGET_SMTP', ''));
        if ($mxTarget === '') {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'SMTP domain target environment variable must be configured.');
        }

        $validationStart = microtime(true);
        $dnsValidatorClass = $this->dnsValidatorClass;
        $mxValidator = new $dnsValidatorClass($mxTarget, Record::TYPE_MX, $dnsServers);
        if (! $mxValidator->isValid($domain->get())) {
            if ($log !== null) {
                $log->addExtra('dnsTimingMx', strval(microtime(true) - $validationStart));
                $log->addTag('dnsDomain', $domain->get());
            }
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, $mxValidator->getDescription());
        }

        $token = $rule->getAttribute('verificationToken', '');
        if ($token === '') {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'SMTP rule verification token is missing.');
        }

        $txtValidator = new $dnsValidatorClass('appwrite-domain-verification='.$token, Record::TYPE_TXT, $dnsServers);
        if (! $txtValidator->isValid('_appwrite.'.$domain->get())) {
            if ($log !== null) {
                $log->addExtra('dnsTimingTxt', strval(microtime(true) - $validationStart));
                $log->addTag('dnsDomain', $domain->get());
            }
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, $txtValidator->getDescription());
        }
    }
}
