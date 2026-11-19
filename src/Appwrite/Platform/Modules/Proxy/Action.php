<?php

namespace Appwrite\Platform\Modules\Proxy;

use Appwrite\Bus\Events\RuleCreated;
use Appwrite\Bus\Events\RuleDeleted;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS as ValidatorDNS;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Utopia\Database\Validator\CustomId;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\Span\Span;
use Utopia\System\System;
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
        if (!$project->isEmpty() && !$this->hasOwnershipProof($rule)) {
            return false;
        }

        $authorization->skip(fn () => $dbForPlatform->deleteDocument('rules', $existingRule->getId()));

        // No deletes job runs for this rule, and the claiming rule gets a certificate of its own
        $certificateId = $existingRule->getAttribute('certificateId', '');
        if ($certificateId !== '') {
            $authorization->skip(fn () => $dbForPlatform->deleteDocument('certificates', $certificateId));
        }

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
        $domain = new Domain($rule->getAttribute('domain', ''));

        if (empty($domain->get())) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain is not valid.');
        }

        if (!$domain->isKnown() || $domain->isTest()) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain ' . $domain->get() . ' does not resolve to a known public apex domain.');
        }

        $validationStart = \microtime(true);
        $records = $this->checkRecords($rule);

        // Keep the latest checks on the rule before any failure, so the caller's write persists them.
        // Found values and message are capped to keep the stored JSON within the attribute size.
        // The check time is stored with them: the rule's own update time also moves on certificate writes.
        $checkedAt = DateTime::now();
        $rule->setAttribute('records', \array_map(fn (array $record) => [
            'type' => $record['type'],
            'name' => $record['name'],
            'value' => $record['value'],
            'found' => \array_map(fn (string $value) => \mb_strcut($value, 0, 255), \array_slice($record['found'], 0, 10)),
            'status' => $record['status'],
            'message' => \mb_strcut($record['message'], 0, 1000),
            'required' => $record['required'],
            'checkedAt' => $checkedAt,
        ], $records));

        // The targets checked below are identical for every project on the instance,
        // so they only prove the domain reaches Appwrite, never whose domain it is.
        // Only its owner can say that, with the ownership proof resolved here.
        if ($this->resolveOwnership($rule) === false) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as domain ' . $domain->get() . ' is assigned to another project. Point its ' . self::OWNERSHIP_PREFIX . $domain->get() . ' TXT record at project ' . $rule->getAttribute('projectId', '') . ' to use it here.');
        }

        // Ensure CAA won't block certificate issuance
        $targets = [];
        foreach ($records as $record) {
            if (!$record['required']) {
                $targets[] = $record;
                continue;
            }

            if (!\is_null($record['error'])) {
                throw $record['error'];
            }

            if ($record['status'] !== 'verified') {
                Span::add('dns.timing_caa', \microtime(true) - $validationStart);
                Span::add('dns.domain', $domain->get());
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, $record['message']);
            }
        }

        if (empty($targets)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'At least one of domain targets environment variable must be configured.');
        }

        if (\in_array('verified', \array_column($targets, 'status'), true)) {
            return;
        }

        // A target that threw only decides the outcome when no other target passed
        foreach ($targets as $target) {
            if (!\is_null($target['error'])) {
                throw $target['error'];
            }
        }

        Span::add('dns.timing', \microtime(true) - $validationStart);
        Span::add('dns.domain', $domain->get());

        // A failure reports the last CNAME target, else the first A, else the AAAA
        $main = null;
        $apex = null; // First A, else AAAA check, to explain an apex
        foreach ($targets as $target) {
            if ($target['type'] === 'CNAME') {
                $main = $target;
                continue;
            }
            $apex ??= $target;
        }
        $main ??= $apex;

        // Explain the record the domain has, such as an A record with the wrong address, rather than
        // a CNAME it was never meant to have. An apex cannot hold a CNAME, so it is told about A or AAAA.
        $explained = $domain->get() === $domain->getApex() ? ($apex ?? $main) : $main;
        foreach ($targets as $target) {
            if ($target['status'] !== 'missing') {
                $explained = $target;
                break;
            }
        }

        throw new Exception(Exception::RULE_VERIFICATION_FAILED, $explained['message']);
    }

    /**
     * Look up every DNS record a rule's domain is checked against, without failing on any of them
     *
     * The CAA record is required. CNAME, A and AAAA records are alternatives: the domain
     * points to Appwrite when any one of them is verified.
     *
     * @param Document $rule Rule to check
     * @return array<array{type: string, name: string, value: string, found: array<string>, status: string, message: string, required: bool, error: ?\Throwable}>
     */
    protected function checkRecords(Document $rule): array
    {
        $dnsValidatorClass = $this->dnsValidatorClass;
        $dnsServers = $this->dnsServers();

        $domain = new Domain($rule->getAttribute('domain', ''));

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
        } elseif ($resourceType === 'function') {
            // Regional target (fra.cloud.appwrite.io) and the sites target
            // (appwrite.network) the console and docs tell users to CNAME.
            $targetCNAMEs[] = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME', ''));
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

        // Each check: record type, expected value, validator, and whether it must pass on its own
        $checks = [];

        // Ensure CAA won't block certificate issuance
        $caaTarget = System::getEnv('_APP_DOMAIN_TARGET_CAA', '');
        if (!empty($caaTarget)) {
            $checks[] = ['CAA', $caaTarget, new $dnsValidatorClass($caaTarget, Record::TYPE_CAA, $dnsServers), true];
        }

        // Ensure at least one of CNAME/A/AAAA record points to our servers properly
        foreach ($targetCNAMEs as $targetCNAME) {
            $checks[] = ['CNAME', $targetCNAME->get(), new $dnsValidatorClass($targetCNAME->get(), Record::TYPE_CNAME, $dnsServers), false];
        }

        foreach (\explode(',', System::getEnv('_APP_DOMAIN_TARGET_A', '')) as $targetA) {
            if (empty($targetA)) {
                continue;
            }
            if ((new IP(IP::V4))->isValid($targetA)) {
                $checks[] = ['A', $targetA, new $dnsValidatorClass($targetA, Record::TYPE_A, $dnsServers), false];
            }
        }

        $targetAAAA = System::getEnv('_APP_DOMAIN_TARGET_AAAA', '');
        if ((new IP(IP::V6))->isValid($targetAAAA)) {
            $checks[] = ['AAAA', $targetAAAA, new $dnsValidatorClass($targetAAAA, Record::TYPE_AAAA, $dnsServers), false];
        }

        // Query CAA and every target at once, so slow resolvers wait in parallel rather than add up
        $wg = new WaitGroup();
        $results = [];
        $errors = [];
        // Outside a coroutine (CLI, tests) go() would leave a reactor running, so check in turn
        $parallel = Coroutine::getCid() > 0;

        foreach ($checks as $key => [, , $validator]) {
            $check = function () use ($validator, $key, $domain, &$results, &$errors): void {
                try {
                    $results[$key] = $validator->isValid($domain->get());
                } catch (\Throwable $error) {
                    // Uncaught in a coroutine it would end the process, so the caller handles it instead
                    $errors[$key] = $error;
                }
            };

            if (!$parallel) {
                $check();
                continue;
            }

            $wg->add();
            \go(function () use ($check, $wg) {
                try {
                    $check();
                } finally {
                    $wg->done();
                }
            });
        }

        if ($parallel) {
            $wg->wait();
        }

        $records = [];
        foreach ($checks as $key => [$type, $value, $validator, $required]) {
            $error = $errors[$key] ?? null;
            $verified = $results[$key] ?? false;

            // A passing lookup across several resolvers keeps no records, yet it found the expected value
            $found = $validator->records;
            if ($verified && empty($found) && $type !== 'CAA') {
                $found = [$value];
            }

            $status = match (true) {
                $verified => 'verified',
                !\is_null($error), $validator->reason !== '' => 'failed',
                $validator->count === 0 => 'missing',
                default => 'mismatch',
            };

            $records[] = [
                'type' => $type,
                'name' => $domain->get(),
                'value' => $value,
                'found' => \array_values($found),
                'status' => $status,
                'message' => match (true) {
                    $verified => '',
                    // Only an Appwrite exception's text is meant for the caller
                    $error instanceof Exception => $error->getMessage(),
                    !\is_null($error) => 'DNS ' . $type . ' lookup failed.',
                    default => $validator->getDescription(),
                },
                'required' => $required,
                'error' => $error,
            ];
        }

        return $records;
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
     * @throws Exception When the proof cannot be resolved, leaving no safe answer
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

        // A query the resolvers cannot answer leaves no records behind, exactly like a
        // domain that genuinely has no proof, and says so only through its reason.
        // Reading one as the other would verify a rule over a published proof whenever
        // the resolvers hiccup, so an unresolved proof is no answer at all.
        if (!empty($validator->reason)) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed as the project assigned to domain ' . $domain . ' could not be resolved. ' . $validator->getDescription());
        }

        // A wildcard or CNAME can answer this name with unrelated TXT records, such as SPF.
        // Only a value that could be a project ID assigns the domain to another project.
        $customId = new CustomId();
        foreach ($validator->records as $record) {
            if ($customId->isValid($record)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Whether the owner of a rule's domain assigned the domain to the rule's own project
     *
     * A proof that cannot be resolved counts as no proof: the domain stays with the
     * project holding it until its owner can be heard from again.
     */
    private function hasOwnershipProof(Document $rule): bool
    {
        try {
            return $this->resolveOwnership($rule) === true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @return array<string> Resolvers every DNS check must agree on
     */
    private function dnsServers(): array
    {
        $servers = \array_map('trim', \explode(',', System::getEnv('_APP_DNS', '8.8.8.8')));

        return \array_values(\array_filter($servers, fn ($server) => !empty($server)));
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
            // Match on a DNS label boundary: the platform domain itself, or a subdomain of it.
            // A bare suffix test would also claim sibling names like 'evil-appwrite.io'.
            if ($domain === $appwriteDomain || \str_ends_with($domain, '.' . $appwriteDomain)) {
                return true;
            }
        }

        return false;
    }
}
