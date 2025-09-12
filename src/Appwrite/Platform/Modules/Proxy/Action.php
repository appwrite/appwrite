<?php

namespace Appwrite\Platform\Modules\Proxy;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS;
use Appwrite\Platform\Action as PlatformAction;
use Utopia\Database\Document;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\IP;

class Action extends PlatformAction
{
    /**
     * Verify or re-verify a rule
     *
     * @param Document $rule Rule to verify
     * @param Log|null $log Log instance to add timings to
     * @param string|null $verificationDomainAPI Override for expected API rule value during verification
     * @param string|null $verificationDomainFunction Override for expected Function rule value during verification
     * @return void
     */
    public static function verifyRule(Document $rule, ?Log $log = null, ?string $verificationDomainAPI = null, ?string $verificationDomainFunction = null): void
    {
        $domain = new Domain($rule->getAttribute('domain', ''));

        // Ensure CAA won't block certificate issuance
        if (!empty(System::getEnv('_APP_DOMAIN_TARGET_CAA', ''))) {
            $validationStart = \microtime(true);
            $validator = new DNS(System::getEnv('_APP_DOMAIN_TARGET_CAA', ''), DNS::RECORD_CAA);
            if (!$validator->isValid($domain->get())) {
                if (!\is_null($log)) {
                    $log->addExtra('dnsTimingCaa', \strval(\microtime(true) - $validationStart));
                    $log->addTag('dnsDomain', $domain->get());

                    $error = $validator->getLogs();
                    $log->addExtra('dnsResponse', \is_array($error) ? \json_encode($error) : \strval($error));
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
            $validator = new DNS($targetCNAME->get(), DNS::RECORD_CNAME);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        if ((new IP(IP::V4))->isValid(System::getEnv('_APP_DOMAIN_TARGET_A', ''))) {
            $validator = new DNS(System::getEnv('_APP_DOMAIN_TARGET_A', ''), DNS::RECORD_A);
            $validators[] = $validator;

            if (\is_null($mainValidator)) {
                $mainValidator = $validator;
            }
        }

        if ((new IP(IP::V6))->isValid(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''))) {
            $validator = new DNS(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''), DNS::RECORD_AAAA);
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

                $errors = [];
                foreach ($validators as $validator) {
                    if (!empty($validator->getLogs())) {
                        $errors[] = \is_array($validator->getLogs()) ? \json_encode($validator->getLogs()) : \strval($validator->getLogs());
                    }
                }
                $error = \implode("\n", $errors);
                $log->addExtra('dnsResponse', $error);
            }

            throw new Exception(Exception::RULE_VERIFICATION_FAILED, $mainValidator->getDescription());
        }
    }
}
