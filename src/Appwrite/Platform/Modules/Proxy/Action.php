<?php

namespace Appwrite\Platform\Modules\Proxy;

use Appwrite\Bus\Events\RuleCreated;
use Appwrite\Bus\Events\RuleDeleted;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS as ValidatorDNS;
use Appwrite\Platform\Action as PlatformAction;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\IP;

class Action extends PlatformAction
{
    /**
     * Prefix of the DNS name holding a domain's ownership proof, a TXT record
     * naming the project the domain's owner assigned it to
     */
    public const string OWNERSHIP_PREFIX = '_appwrite.';

    public function __construct(protected string $dnsValidatorClass = ValidatorDNS::class)
    {
    }

    protected function createRule(Document $rule, Database $dbForPlatform, Authorization $authorization, Bus $bus): Document
    {
        try {
            return $this->created($authorization->skip(fn () => $dbForPlatform->createDocument('rules', $rule)), $bus);
        } catch (Duplicate) {
            if (!$this->releaseDomain($rule, $dbForPlatform, $authorization, $bus)) {
                throw new Exception(Exception::RULE_ALREADY_EXISTS);
            }
        }

        try {
            return $this->created($authorization->skip(fn () => $dbForPlatform->createDocument('rules', $rule)), $bus);
        } catch (Duplicate) {
            throw new Exception(Exception::RULE_ALREADY_EXISTS);
        }
    }

    private function created(Document $rule, Bus $bus): Document
    {
        $bus->dispatch(new RuleCreated($rule->getArrayCopy()));

        return $rule;
    }

    /**
     * Free a domain held by an existing rule, when the claiming rule is entitled to it
     *
     * A domain is freed when the rule holding it is orphaned, its project having
     * been deleted, or when the domain's owner published an ownership proof for
     * the claiming project. DNS is the only authority on whose domain it is.
     *
     * @param Document $rule Rule claiming the domain
     * @return bool Whether the domain is free for the claiming rule
     */
    private function releaseDomain(Document $rule, Database $dbForPlatform, Authorization $authorization, Bus $bus): bool
    {
        $existingRule = $authorization->skip(function () use ($rule, $dbForPlatform) {
            $existingRule = $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$rule->getAttribute('domain', '')]),
            ]);
            if (!$existingRule->isEmpty()) {
                return $existingRule;
            }

            return $dbForPlatform->getDocument('rules', $rule->getId());
        });

        if ($existingRule->isEmpty()) {
            return true;
        }

        if ($existingRule->getAttribute('domain', '') !== $rule->getAttribute('domain', '')) {
            return false;
        }

        $projectId = $existingRule->getAttribute('projectId', '');
        if (empty($projectId)) {
            return false;
        }

        // A project that already holds the domain keeps its rule, proof or not
        if ($projectId === $rule->getAttribute('projectId', '')) {
            return false;
        }

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
        if (!$project->isEmpty() && $this->resolveOwnership($rule) !== true) {
            return false;
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('rules', $existingRule->getId()));
        $bus->dispatch(new RuleDeleted($existingRule->getArrayCopy()));

        return true;
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

        $functionsDomains = System::getEnv('_APP_DOMAIN_FUNCTIONS', '') . ',' . System::getEnv('_APP_DOMAIN_FUNCTIONS_FALLBACK', '');
        foreach (\explode(',', $functionsDomains) as $functionsDomain) {
            if (empty($functionsDomain)) {
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
     * @return void
     */
    protected function verifyRule(Document $rule): void
    {
        $dnsValidatorClass = $this->dnsValidatorClass;
        $dnsServers = $this->dnsServers();

        $domain = new Domain($rule->getAttribute('domain', ''));

        if (empty($domain->get())) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain is not valid.');
        }

        if (!$domain->isKnown() || $domain->isTest()) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain ' . $domain->get() . ' does not resolve to a known public apex domain.');
        }

        // The targets checked below are identical for every project on the instance,
        // so they only prove the domain reaches Appwrite, never whose domain it is.
        // Only its owner can say that, with the ownership proof resolved here.
        if ($this->resolveOwnership($rule) === false) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain ' . $domain->get() . ' is assigned to another project. Point its ' . self::OWNERSHIP_PREFIX . $domain->get() . ' TXT record at project ' . $rule->getAttribute('projectId', '') . ' to use it here.');
        }

        // Ensure CAA won't block certificate issuance
        $caaTarget = System::getEnv('_APP_DOMAIN_TARGET_CAA', '');
        if (!empty($caaTarget)) {
            $validationStart = \microtime(true);
            $validator = new $dnsValidatorClass($caaTarget, Record::TYPE_CAA, $dnsServers);
            if (!$validator->isValid($domain->get())) {
                Span::add('dns.timing_caa', \microtime(true) - $validationStart);
                Span::add('dns.domain', $domain->get());
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, $validator->getDescription());
            }
        }

        $targetCNAMEs = [];
        $ruleType = $rule->getAttribute('type', '');
        $resourceType = $rule->getAttribute('deploymentResourceType', '');

        // Ensures different target based on rule's type, as configured by env variables
        if ($resourceType === 'site') {
            // For example: appwrite.network
            foreach (\explode(',', System::getEnv('_APP_DOMAIN_SITES', '')) as $targetCNAME) {
                if (empty($targetCNAME)) {
                    continue;
                }
                $targetCNAMEs[] = new Domain($targetCNAME);
            }
        } elseif ($resourceType === 'function' || $ruleType === 'api') {
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
                $cnameValidators[] = new $dnsValidatorClass($targetCNAME->get(), Record::TYPE_CNAME, $dnsServers);
            }

            $validator = new AnyOf($cnameValidators);
            $validators[] = $validator;

            $mainValidator = $validator;
        }

        // Ensure at least one of CNAME/A/AAAA record points to our servers properly
        foreach (\explode(',', System::getEnv('_APP_DOMAIN_TARGET_A', '')) as $targetA) {
            if (empty($targetA)) {
                continue;
            }
            if ((new IP(IP::V4))->isValid($targetA)) {
                $validator = new $dnsValidatorClass($targetA, Record::TYPE_A, $dnsServers);
                $validators[] = $validator;

                if (\is_null($mainValidator)) {
                    $mainValidator = $validator;
                }
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
            Span::add('dns.timing', \microtime(true) - $validationStart);
            Span::add('dns.domain', $domain->get());
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, $mainValidator->getDescription());
        }
    }

    /**
     * Resolve which project the owner of a rule's domain assigned the domain to
     *
     * Ownership lives in a TXT record at `_appwrite.<domain>` holding a project ID.
     * It stays optional, so a domain pointed at Appwrite with nothing but a CNAME
     * still onboards, but it is authoritative wherever it is published.
     *
     * @param Document $rule Rule claiming the domain
     * @return bool|null True when the proof names the rule's own project, false when
     *  it names another one, and null when the owner published no proof at all
     */
    protected function resolveOwnership(Document $rule): ?bool
    {
        $domain = $rule->getAttribute('domain', '');
        $projectId = $rule->getAttribute('projectId', '');

        if (empty($domain) || empty($projectId)) {
            return null;
        }

        $dnsValidatorClass = $this->dnsValidatorClass;
        $validator = new $dnsValidatorClass($projectId, Record::TYPE_TXT, $this->dnsServers());

        if ($validator->isValid(self::OWNERSHIP_PREFIX . $domain)) {
            return true;
        }

        return $validator->count > 0 ? false : null;
    }

    /**
     * @return array<string> Resolvers every DNS check must agree on
     */
    private function dnsServers(): array
    {
        $servers = \array_map('trim', \explode(',', System::getEnv('_APP_DNS', '8.8.8.8')));

        return \array_filter($servers, fn ($server) => !empty($server));
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
