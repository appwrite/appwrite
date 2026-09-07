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
            ->label('event', 'rules.[ruleId].update')
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
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('log')
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
        Log $log,
        Authorization $authorization,
        Bus $bus,
    ) {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $queueForEvents->setParam('ruleId', $rule->getId());

        // If rule is already verified or in certificate generation state, don't queue for verification again
        if ($rule->getAttribute('status') === RULE_STATUS_VERIFIED || $rule->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATING) {
            $response->dynamic($rule, Response::MODEL_PROXY_RULE);
            return;
        }

        try {
            $this->verifyRule($rule, $log);
        } catch (Exception $err) {
            $rule = $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                '$updatedAt' => DateTime::now(),
            ])));
            $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));
            throw $err;
        }

        // DNS can take time. Re-check ownership and status under a lock so two
        // requests cannot reset an attempt budget while a worker is issuing.
        [$rule, $certificate, $queued] = $authorization->skip(fn () => $dbForPlatform->withTransaction(function () use ($dbForPlatform, $rule, $project): array {
            $current = $dbForPlatform->getDocument('rules', $rule->getId(), forUpdate: true);
            if ($current->isEmpty()
                || $current->getAttribute('projectInternalId') !== $project->getSequence()
                || $current->getAttribute('domain') !== $rule->getAttribute('domain')) {
                throw new Exception(Exception::RULE_NOT_FOUND);
            }
            if (\in_array($current->getAttribute('status'), [RULE_STATUS_VERIFIED, RULE_STATUS_CERTIFICATE_GENERATING], true)) {
                return [$current, new Document(), false];
            }

            $certificateId = $current->getAttribute('certificateId', '');
            $certificate = empty($certificateId) ? new Document() : $dbForPlatform->getDocument('certificates', $certificateId, forUpdate: true);
            if (!$certificate->isEmpty()) {
                // A requested retry starts a fresh budget only after DNS passes.
                $certificate = $dbForPlatform->updateDocument('certificates', $certificateId, new Document([
                    'logs' => '',
                    'attempts' => 0,
                    'updated' => null,
                ]));
            }
            $current = $dbForPlatform->updateDocument('rules', $current->getId(), new Document([
                'logs' => '',
                'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            ]));
            return [$current, $certificate, true];
        }));
        if (!$queued) {
            $response->dynamic($rule, Response::MODEL_PROXY_RULE);
            return;
        }
        $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

        // Issue a TLS certificate when DNS verification is successful
        $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
            project: $project,
            domain: new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]),
        ));

        if (!$certificate->isEmpty()) {
            $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
        }

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
