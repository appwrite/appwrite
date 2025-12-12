<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Verification;

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\DNS\Message\Record;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\IP;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateRuleVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/proxy/rules/:ruleId/verification')
            ->desc('Update rule verification status')
            ->groups(['api', 'proxy'])
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].update')
            ->label('audits.event', 'rule.update')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: null,
                name: 'updateRuleVerification',
                description: <<<EOT
                Retry getting verification process of a proxy rule. This endpoint triggers domain verification by checking DNS records (CNAME) against the configured target domain. If verification is successful, a TLS certificate will be automatically provisioned for the domain.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->param('ruleId', '', new UID(), 'Rule ID.')
            ->inject('response')
            ->inject('queueForCertificates')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Certificate $queueForCertificates,
        Event $queueForEvents,
        Document $project,
        Database $dbForPlatform,
        Log $log
    ) {
        $rule = $dbForPlatform->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $targetCNAME = null;
        switch ($rule->getAttribute('type', '')) {
            case 'api':
                // For example: fra.cloud.appwrite.io
                $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME', ''));
                break;
            case 'redirect':
                // For example: appwrite.network
                $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
                break;
            case 'deployment':
                switch ($rule->getAttribute('deploymentResourceType', '')) {
                    case 'function':
                        // For example: fra.appwrite.run
                        $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_FUNCTIONS', ''));
                        break;
                    case 'site':
                        // For example: appwrite.network
                        $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
                        break;
                    default:
                        break;
                }
                // no break
            default:
                break;
        }

        $validators = [];

        if (!is_null($targetCNAME)) {
            if ($targetCNAME->isKnown() && !$targetCNAME->isTest()) {
                $validators[] = new DNS($targetCNAME->get(), Record::TYPE_CNAME);
            }
        }

        if ((new IP(IP::V4))->isValid(System::getEnv('_APP_DOMAIN_TARGET_A', ''))) {
            $validators[] = new DNS(System::getEnv('_APP_DOMAIN_TARGET_A', ''), Record::TYPE_A);
        }
        if ((new IP(IP::V6))->isValid(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''))) {
            $validators[] = new DNS(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''), Record::TYPE_AAAA);
        }

        if (empty($validators)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'At least one of domain targets environment variable must be configured.');
        }

        if ($rule->getAttribute('verification') === true) {
            return $response->dynamic($rule, Response::MODEL_PROXY_RULE);
        }

        $validator = new AnyOf($validators, AnyOf::TYPE_STRING);
        $domain = new Domain($rule->getAttribute('domain', ''));

        $validationStart = \microtime(true);
        if (!$validator->isValid($domain->get())) {
            $log->addExtra('dnsTiming', \strval(\microtime(true) - $validationStart));
            $log->addTag('dnsDomain', $domain->get());
            throw new Exception(Exception::RULE_VERIFICATION_FAILED);
        }

        // Ensure CAA won't block certificate issuance
        if (!empty(System::getEnv('_APP_DOMAIN_TARGET_CAA', ''))) {
            $validationStart = \microtime(true);
            $validator = new DNS(System::getEnv('_APP_DOMAIN_TARGET_CAA', ''), Record::TYPE_CAA);
            if (!$validator->isValid($domain->get())) {
                $log->addExtra('dnsTimingCaa', \strval(\microtime(true) - $validationStart));
                $log->addTag('dnsDomain', $domain->get());
                $error = $validator->getDescription();
                $log->addExtra('dnsResponse', \is_array($error) ? \json_encode($error) : \strval($error));
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'Domain verification failed because CAA records do not allow Appwrite\'s certificate issuer.');
            }
        }

        $dbForPlatform->updateDocument('rules', $rule->getId(), $rule->setAttribute('status', 'verifying'));

        // Issue a TLS certificate when domain is verified
        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]))
            ->trigger();

        $queueForEvents->setParam('ruleId', $rule->getId());

        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
