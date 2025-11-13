<?php

namespace Appwrite\Platform\Modules\Proxy;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Utopia\Database\Document;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS as ValidatorDNS;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\IP;

class Action extends PlatformAction
{
    public function __construct(protected string $dnsValidatorClass = ValidatorDNS::class)
    {
    }

    /**
     * Verify or re-verify a rule
     *
     * @param Document $rule Rule to verify
     * @param Log|null $log Log instance to add timings to
     * @param string|null $verificationDomainAPI Override for expected API rule value during verification
     * @param string|null $verificationDomainFunction Override for expected Function rule value during verification
     * @return void
     */
    public function verifyRule(Document $rule, ?Log $log = null, ?string $verificationDomainAPI = null, ?string $verificationDomainFunction = null): void
    {
        $dnsValidatorClass = $this->dnsValidatorClass;

        $domain = new Domain($rule->getAttribute('domain', ''));

        if (empty($domain->get())) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed because domain is not valid.');
        }

        if (!$domain->isKnown() || $domain->isTest()) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'DNS verification failed because domain ' . $domain->get() . ' is not known public suffix.');
        }

        // Ensure CAA won't block certificate issuance
        $caaTarget = System::getEnv('_APP_DOMAIN_TARGET_CAA', '');
        if (!empty($caaTarget)) {
            $validationStart = \microtime(true);
            $validator = new $dnsValidatorClass($caaTarget, Record::TYPE_CAA, System::getEnv('_APP_DNS', '8.8.8.8'));
            if (!$validator->isValid($domain->get())) {
                if (!\is_null($log)) {
                    $log->addExtra('dnsTimingCaa', \strval(\microtime(true) - $validationStart));
                    $log->addTag('dnsDomain', $domain->get());
                }
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, $validator->getDescription());
            }
        }

        // Ensure at least one of CNAME/A/AAAA record points to our servers properly
        // Ensures different target based on rule's type, as configured by env variables
        if (\is_null($verificationDomainAPI)) {
            $verificationDomainAPI = System::getEnv('_APP_DOMAIN_TARGET_CNAME', '');
        }
        if (\is_null($verificationDomainFunction)) {
            $verificationDomainFunction = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
        }

        $targetCNAME = null;
        $ruleType = $rule->getAttribute('type', '');
        $resourceType = $rule->getAttribute('deploymentResourceType', '');

        if ($resourceType === 'function') {
            // For example: fra.appwrite.run
            $targetCNAME = new Domain($verificationDomainFunction);
        } elseif ($resourceType === 'site') {
            // For example: appwrite.network
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
        } elseif ($ruleType === 'api') {
            // For example: fra.cloud.appwrite.io
            $targetCNAME = new Domain($verificationDomainAPI);
        } elseif ($ruleType === 'redirect') {
            // Shouldn't be needed, because redirect should always have resourceTyp too, but just in case we defailt to sites
            // For example: appwrite.network
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
        }

        $validators = [];
        $mainValidator = null; // Validator to use for error description

        if (!is_null($targetCNAME)) {
            $validator = new $dnsValidatorClass($targetCNAME->get(), Record::TYPE_CNAME, System::getEnv('_APP_DNS', '8.8.8.8'));
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        $targetA = System::getEnv('_APP_DOMAIN_TARGET_A', '');
        if ((new IP(IP::V4))->isValid($targetA)) {
            $validator = new $dnsValidatorClass($targetA, Record::TYPE_A, System::getEnv('_APP_DNS', '8.8.8.8'));
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        $targetAAAA = System::getEnv('_APP_DOMAIN_TARGET_AAAA', '');
        if ((new IP(IP::V6))->isValid($targetAAAA)) {
            $validator = new $dnsValidatorClass($targetAAAA, Record::TYPE_AAAA, System::getEnv('_APP_DNS', '8.8.8.8'));
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
