<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS as ValidatorDNS;
use Appwrite\Platform\Action as PlatformAction;
use Utopia\Database\Document;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\IP;

class Action extends PlatformAction
{
    public function __construct(protected string $dnsValidatorClass = ValidatorDNS::class)
    {
    }

    /**
     * Ensures domain is not in the deny list and is a valid domain
     *
     * @param string $domain Domain to validate
     * @param array $platform Platform configuration which has internal domains
     * @throws Exception
     * @return void
     */
    protected function validateDomainRestrictions(string $domain, array $platform): void
    {
        $domains = $platform['hostnames'] ?? [];
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        $restrictions = [];
        if (!empty($sitesDomain)) {
            $domainLevel = \count(\explode('.', $sitesDomain));
            $restrictions[] = ValidatorDomain::createRestriction($sitesDomain, $domainLevel + 1, ['commit-', 'branch-']);
        }
        if (!empty($functionsDomain)) {
            $domainLevel = \count(\explode('.', $functionsDomain));
            $restrictions[] = ValidatorDomain::createRestriction($functionsDomain, $domainLevel + 1);
        }
        $validator = new ValidatorDomain($restrictions);

        if (!$validator->isValid($domain)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
        }

        $deniedDomains = [...$domains];

        if (!empty($sitesDomain)) {
            $deniedDomains[] = $sitesDomain;
        }

        if (!empty($functionsDomain)) {
            $deniedDomains[] = $functionsDomain;
        }

        $denyListDomains = System::getEnv('_APP_CUSTOM_DOMAIN_DENY_LIST', '');
        $denyListDomains = \array_map('trim', explode(',', $denyListDomains));
        foreach ($denyListDomains as $denyListDomain) {
            if (empty($denyListDomain)) {
                continue;
            }
            $deniedDomains[] = $denyListDomain;
        }

        if (\in_array($domain, $deniedDomains)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
        }

        try {
            $domain = new Domain($domain);
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain may not start with http:// or https://.');
        }
    }

    /**
     * Verify or re-verify a rule
     *
     * @param Document $rule Rule to verify
     * @param Log|null $log Log instance to add timings to
     * @return void
     */
    protected function verifyRule(Document $rule, ?Log $log = null): void
    {
        $dnsValidatorClass = $this->dnsValidatorClass;
        $dnsEnv = System::getEnv('_APP_DNS', '8.8.8.8');
        $servers = \array_map('trim', \explode(',', $dnsEnv));
        $dnsServers = \array_filter($servers, fn ($server) => !empty($server));

        $domain = new Domain($rule->getAttribute('domain', ''));

        if (empty($domain->get())) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain is not valid.');
        }

        if (!$domain->isKnown() || $domain->isTest()) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain ' . $domain->get() . ' does not resolve to a known public apex domain.');
        }

        // Ensure CAA won't block certificate issuance
        $caaTarget = System::getEnv('_APP_DOMAIN_TARGET_CAA', '');
        if (!empty($caaTarget)) {
            $validationStart = \microtime(true);
            $validator = new $dnsValidatorClass($caaTarget, Record::TYPE_CAA, $dnsServers);
            if (!$validator->isValid($domain->get())) {
                if (!\is_null($log)) {
                    $log->addExtra('dnsTimingCaa', \strval(\microtime(true) - $validationStart));
                    $log->addTag('dnsDomain', $domain->get());
                }
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, $validator->getDescription());
            }
        }

        $targetCNAME = null;
        $ruleType = $rule->getAttribute('type', '');
        $resourceType = $rule->getAttribute('deploymentResourceType', '');

        // Ensures different target based on rule's type, as configured by env variables
        if ($resourceType === 'function') {
            // For example: fra.appwrite.run
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_FUNCTIONS', ''));
        } elseif ($resourceType === 'site') {
            // For example: appwrite.network
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
        } elseif ($ruleType === 'api') {
            // For example: fra.cloud.appwrite.io
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME', ''));
        } elseif ($ruleType === 'redirect') {
            // Shouldn't be needed, because redirect should always have resourceTyp too, but just in case we default to sites
            // For example: appwrite.network
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
        }

        $validators = [];
        $mainValidator = null; // Validator to use for error description

        if (!is_null($targetCNAME)) {
            $validator = new $dnsValidatorClass($targetCNAME->get(), Record::TYPE_CNAME, $dnsServers);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        // Ensure at least one of CNAME/A/AAAA record points to our servers properly
        $targetA = System::getEnv('_APP_DOMAIN_TARGET_A', '');
        if ((new IP(IP::V4))->isValid($targetA)) {
            $validator = new $dnsValidatorClass($targetA, Record::TYPE_A, $dnsServers);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        $targetAAAA = System::getEnv('_APP_DOMAIN_TARGET_AAAA', '');
        if ((new IP(IP::V6))->isValid($targetAAAA)) {
            $validator = new $dnsValidatorClass($targetAAAA, Record::TYPE_AAAA, $dnsServers);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        if (empty($validators)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'At least one of domain targets environment variable must be configured.');
        }

        $validator = new AnyOf($validators, AnyOf::TYPE_STRING);

        $validationStart = \microtime(true);
        if (!$validator->isValid($domain->get())) {
            if (!\is_null($log)) {
                $log->addExtra('dnsTiming', \strval(\microtime(true) - $validationStart));
                $log->addTag('dnsDomain', $domain->get());
            }
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, $mainValidator->getDescription());
        }
    }
}
