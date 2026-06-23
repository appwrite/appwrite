<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\API;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createAPIRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/proxy/rules/api')
            ->groups(['api', 'proxy'])
            ->desc('Create API rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].create')
            ->label('audits.event', 'rule.create')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'createAPIRule',
                description: <<<EOT
                Create a new proxy rule for serving Appwrite's API on custom domain.

                Rule ID is automatically generated as MD5 hash of a rule domain for performance purposes.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('domain', null, new ValidatorDomain(), 'Domain name.')
            ->inject('response')
            ->inject('project')
            ->inject('publisherForCertificates')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('log')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $domain,
        Response $response,
        Document $project,
        Certificate $publisherForCertificates,
        Event $queueForEvents,
        Database $dbForPlatform,
        array $platform,
        Log $log,
        Authorization $authorization,
    ) {
        $this->validateDomainRestrictions($domain, $platform);

        // TODO: (@Meldiron) Remove after 1.7.x migration
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5(\strtolower($domain)) : ID::unique();
        $status = RULE_STATUS_CREATED;
        $owner = '';

        if ($this->isAppwriteOwned($domain)) {
            $status = RULE_STATUS_VERIFIED;
            $owner = 'Appwrite';
        }

        $rule = new Document([
            '$id' => $ruleId,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'domain' => $domain,
            'status' => $status,
            'type' => 'api',
            'trigger' => 'manual',
            'certificateId' => '',
            'search' => implode(' ', [$ruleId, $domain]),
            'owner' => $owner,
            'region' => $project->getAttribute('region')
        ]);

        if ($rule->getAttribute('status', '') === RULE_STATUS_CREATED) {
            try {
                $this->verifyRule($rule, $log);
                $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATING);
            } catch (Exception $err) {
                $rule->setAttribute('logs', $err->getMessage());
            }
        }

        $rule = $this->createRule($rule, $dbForPlatform, $authorization);

        if ($rule->getAttribute('status', '') === RULE_STATUS_CERTIFICATE_GENERATING) {
            $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
                project: $project,
                domain: new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]),
                action: \Appwrite\Event\Certificate::ACTION_GENERATION,
            ));
        }

        $queueForEvents->setParam('ruleId', $rule->getId());

        // Rename 'created' status to 'unverified' for consistency.
        // 'verifying' and 'verified' statuses stay as is.
        // 'unverified' in the meaning of failed certificate generation stays as is.
        if ($rule->getAttribute('status') === 'created') {
            $rule->setAttribute('status', 'unverified');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
