<?php

namespace Appwrite\Platform\Modules\Proxy;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS as DNSValidator;
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

        $deniedDomains = [...$domains];
        $restrictions = [];

        $sitesDomains = System::getEnv('_APP_DOMAIN_SITES', '');
        foreach (\explode(',', $sitesDomains) as $sitesDomain) {
            if (empty($sitesDomain)) {
                continue;
            }

            $deniedDomains[] = $sitesDomain;

            // Ensure site domains are exactly 1 subdomain, and dont start with reserved prefix
            $domainLevel = \count(\explode('.', $sitesDomain));
            $restrictions[] = ValidatorDomain::createRestriction($sitesDomain, $domainLevel + 1, ['commit-', 'branch-']);
        }

        $functionsDomains = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
        foreach (\explode(',', $functionsDomains) as $functionsDomain) {
            if (empty($functionsDomains)) {
                continue;
            }

            $deniedDomains[] = $functionsDomain;

            // Ensure function domains are exactly 1 subdomain
            $domainLevel = \count(\explode('.', $functionsDomain));
            $restrictions[] = ValidatorDomain::createRestriction($functionsDomain, $domainLevel + 1);
        }

        $validator = new ValidatorDomain($restrictions);

        if (!$validator->isValid($domain)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
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
     * @param DNSValidator $dnsValidator DNS validator instance with configured DNS servers
     * @param Log|null $log Log instance to add timings to
     * @return void
     */
    protected function verifyRule(Document $rule, DNSValidator $dnsValidator, ?Log $log = null): void
    {
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
            $validator = $dnsValidator->forRecord($caaTarget, Record::TYPE_CAA);
            if (!$validator->isValid($domain->get())) {
                if (!\is_null($log)) {
                    $log->addExtra('dnsTimingCaa', \strval(\microtime(true) - $validationStart));
                    $log->addTag('dnsDomain', $domain->get());
                }
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, $validator->getDescription());
            }
        }

        $targetCNAMEs = [];
        $ruleType = $rule->getAttribute('type', '');
        $resourceType = $rule->getAttribute('deploymentResourceType', '');

        // Ensures different target based on rule's type, as configured by env variables
        if ($resourceType === 'function') {
            // For example: fra.appwrite.run
            foreach (\explode(',', System::getEnv('_APP_DOMAIN_FUNCTIONS', '')) as $targetCNAME) {
                if (empty($targetCNAME)) {
                    continue;
                }
                $targetCNAMEs[] = new Domain($targetCNAME);
            }
        } elseif ($resourceType === 'site') {
            // For example: appwrite.network
            foreach (\explode(',', System::getEnv('_APP_DOMAIN_SITES', '')) as $targetCNAME) {
                if (empty($targetCNAME)) {
                    continue;
                }
                $targetCNAMEs[] = new Domain($targetCNAME);
            }
        } elseif ($ruleType === 'api') {
            // For example: fra.cloud.appwrite.io
            $targetCNAMEs[] = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME', ''));
        } elseif ($ruleType === 'redirect') {
            // Shouldn't be needed, because redirect should always have resourceTyp too, but just in case we default to sites
            // For example: appwrite.network
            foreach (\explode(',', System::getEnv('_APP_DOMAIN_SITES', '')) as $targetCNAME) {
                if (empty($targetCNAME)) {
                    continue;
                }
                $targetCNAMEs[] = new Domain($targetCNAME);
            }
        }

        $validators = [];
        $mainValidator = null; // Validator to use for error description

        if (\count($targetCNAMEs) > 0) {
            $cnameValidators = [];
            foreach ($targetCNAMEs as $targetCNAME) {
                $cnameValidators[] = $dnsValidator->forRecord($targetCNAME->get(), Record::TYPE_CNAME);
            }

            $validator = new AnyOf($cnameValidators);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        // Ensure at least one of CNAME/A/AAAA record points to our servers properly
        $targetA = System::getEnv('_APP_DOMAIN_TARGET_A', '');
        if ((new IP(IP::V4))->isValid($targetA)) {
            $validator = $dnsValidator->forRecord($targetA, Record::TYPE_A);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        $targetAAAA = System::getEnv('_APP_DOMAIN_TARGET_AAAA', '');
        if ((new IP(IP::V6))->isValid($targetAAAA)) {
            $validator = $dnsValidator->forRecord($targetAAAA, Record::TYPE_AAAA);
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

    protected function isAppwriteOwned(string $domain): bool
    {
        $appwriteDomains = [];
        $appwriteDomainEnvs = [
            System::getEnv('_APP_DOMAIN_FUNCTIONS_FALLBACK', ''),
            System::getEnv('_APP_DOMAIN_FUNCTIONS', ''),
            System::getEnv('_APP_DOMAIN_SITES', ''),
        ];
        foreach ($appwriteDomainEnvs as $appwriteDomainEnv) {
            foreach (\explode(',', $appwriteDomainEnv) as $appwriteDomain) {
                if (empty($appwriteDomain)) {
                    continue;
                }
                $appwriteDomains[] = $appwriteDomain;
            }
        }

        foreach ($appwriteDomains as $appwriteDomain) {
            if (\str_ends_with($domain, $appwriteDomain)) {
                return true;
            }
        }

        return false;
    }
}
