<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Verification;

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateRuleVerification';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

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

        $queueForEvents->setParam('ruleId', $rule->getId());

        // If rule is already verified or in certificate generation state, don't queue for verification again
        if ($rule->getAttribute('status') === RULE_STATUS_VERIFIED || $rule->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATING) {
            return $response->dynamic($rule, Response::MODEL_PROXY_RULE);
        }

        try {
            $this->verifyRule($rule, $log);
            // Reset logs and status for the rule
            $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'logs' => '',
                'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            ]));

            $certificateId = $rule->getAttribute('certificateId', '');
            // Reset logs for the associated certificate.
            if (!empty($certificateId)) {
                $certificate = $dbForPlatform->updateDocument('certificates', $certificateId, new Document([
                    'logs' => '',
                ]));
            }
        } catch (Exception $err) {
            $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                '$updatedAt' => DateTime::now(),
            ]));
            throw $err;
        }

        // Issue a TLS certificate when DNS verification is successful
        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]))
            ->trigger();

        if (!empty($certificate)) {
            $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
        }

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
