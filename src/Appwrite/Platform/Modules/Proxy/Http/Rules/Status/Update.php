<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Status;

use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
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
            ->setHttpPath('/v1/proxy/rules/:ruleId/status')
            ->httpAlias('/v1/proxy/rules/:ruleId/verification')
            ->desc('Update rule status')
            ->groups(['api', 'proxy'])
            ->label('scope', 'rules.write')
            ->label('audits.event', 'rule.update')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'updateRuleStatus',
                description: <<<EOT
                If not succeeded yet, retry verification process of a proxy rule domain. This endpoint triggers domain verification by checking DNS records. If verification is successful, a TLS certificate will be automatically provisioned for the domain asynchronously in the background.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->param('ruleId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Rule ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('publisherForCertificates')
            ->inject('bus')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('log')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Certificate $publisherForCertificates,
        Bus $bus,
        Document $project,
        Database $dbForPlatform,
        Log $log,
        Authorization $authorization,
        Document $actor,
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        // If rule is already verified or in certificate generation state, don't queue for verification again
        if ($rule->getAttribute('status') === RULE_STATUS_VERIFIED || $rule->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATING) {
            $response->dynamic($rule, Response::MODEL_PROXY_RULE);

            $bus->dispatch(new RuleUpdated($rule, $project, $actor));

            return;
        }

        try {
            $this->verifyRule($rule, $log);
            // Reset logs and status for the rule
            $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'logs' => '',
                'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            ])));

            $certificateId = $rule->getAttribute('certificateId', '');
            // Reset logs for the associated certificate.
            if (!empty($certificateId)) {
                $certificate = $authorization->skip(fn () => $dbForPlatform->updateDocument('certificates', $certificateId, new Document([
                    'logs' => '',
                ])));
            }
        } catch (Exception $err) {
            $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                '$updatedAt' => DateTime::now(),
            ])));
            throw $err;
        }

        // Issue a TLS certificate when DNS verification is successful
        $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
            project: $project,
            domain: new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]),
        ));

        if (!empty($certificate)) {
            $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
        }

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);

        $bus->dispatch(new RuleUpdated($rule, $project, $actor));
    }
}
