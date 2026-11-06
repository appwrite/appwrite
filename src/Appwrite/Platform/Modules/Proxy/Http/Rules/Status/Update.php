<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Status;

use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Cdn\Certificates\Status;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
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
            ->label('event', 'rules.[ruleId].update')
            ->label('audits.event', 'rule.update')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'updateRuleStatus',
                description: <<<EOT
                If not succeeded yet, retry verification process of a proxy rule domain. This endpoint triggers domain verification by checking DNS records. If verification is successful, a TLS certificate will be automatically provisioned for the domain asynchronously in the background. A verified rule whose certificate renewal failed or is blocked, or whose certificate expired, is checked the same way, and its certificate renewed in the background.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->param('ruleId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Rule ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('publisherForCertificates')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        Response $response,
        Certificate $publisherForCertificates,
        Event $queueForEvents,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $queueForEvents->setParam('ruleId', $rule->getId());

        $certificate = $authorization->skip(fn () => $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', '')));
        $expireDate = $certificate->getAttribute('expireDate');

        // A verified rule whose renewal failed or is blocked, or whose certificate expired, is checked again.
        // Maintenance stops after 5 failed attempts, so once DNS is fixed nothing else would renew it.
        $renewal = $rule->getAttribute('status') === RULE_STATUS_VERIFIED
            && (\in_array($certificate->getAttribute('status'), [Status::FAILED, Status::BLOCKED], true) || (!empty($expireDate) && \strtotime($expireDate) <= \time()));

        // If rule is already verified or in certificate generation state, don't queue for verification again
        if (!$renewal && ($rule->getAttribute('status') === RULE_STATUS_VERIFIED || $rule->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATING)) {
            $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
            $rule->setAttribute('expireAt', $certificate->getAttribute('expireDate', ''));
            $rule->setAttribute('certificateStatus', $certificate->getAttribute('status', ''));

            $response->dynamic($rule, Response::MODEL_PROXY_RULE);
            return;
        }

        try {
            // Appwrite-owned subdomains ride a wildcard record, so the worker never checks their DNS either
            if ($rule->getAttribute('owner') !== 'Appwrite') {
                $this->verifyRule($rule);
            }
            // Reset logs and status for the rule. A renewal stays verified: its current certificate keeps serving.
            $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'logs' => '',
                'status' => $renewal ? RULE_STATUS_VERIFIED : RULE_STATUS_CERTIFICATE_GENERATING,
            ])));
            $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

            $certificateId = $rule->getAttribute('certificateId', '');
            // Reset logs, attempts and the last reported state for the associated certificate. A renewal keeps
            // its state until the renewal reports, so the domain does not read as healthy before it is.
            if (!empty($certificateId)) {
                $reset = new Document([
                    'logs' => '',
                    'attempts' => 0,
                ]);
                if (!$renewal) {
                    $reset->setAttribute('status', '');
                }
                $certificate = $authorization->skip(fn () => $dbForPlatform->updateDocument('certificates', $certificateId, $reset));
            }
        } catch (Exception $err) {
            // Record why DNS verification failed, so the logs do not keep showing an older reason.
            // Other errors, such as a platform misconfiguration, are not the domain owner's to fix.
            $failed = $err->getType() === Exception::RULE_VERIFICATION_FAILED;
            $update = new Document(['$updatedAt' => DateTime::now()]);
            if ($failed) {
                $update->setAttribute('logs', $err->getMessage());
            }
            $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), $update));
            $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

            // Certificate logs take precedence when a rule is read, so overwrite them too
            if ($failed && !empty($certificate->getAttribute('logs', ''))) {
                $authorization->skip(fn () => $dbForPlatform->updateDocument('certificates', $certificate->getId(), new Document([
                    'logs' => $err->getMessage(),
                ])));
            }
            throw $err;
        }

        // Issue a TLS certificate when DNS verification is successful. The worker
        // does not verify DNS again: verifyRule() above just did.
        $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
            project: $project,
            domain: new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]),
            skipDomainValidation: true,
        ));

        $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
        $rule->setAttribute('expireAt', $certificate->getAttribute('expireDate', ''));
        $rule->setAttribute('certificateStatus', $certificate->getAttribute('status', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
