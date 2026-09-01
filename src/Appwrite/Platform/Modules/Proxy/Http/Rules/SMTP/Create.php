<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\SMTP;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSMTPRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/proxy/rules/smtp')
            ->groups(['api', 'proxy'])
            ->desc('Create SMTP function rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].create')
            ->label('audits.event', 'rule.create')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'createSMTPRule',
                description: 'Create an SMTP ingress rule that asynchronously executes an Appwrite Function for incoming email.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PROXY_RULE,
                    ),
                ]
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('domain', null, new ValidatorDomain(), 'Domain that receives email.')
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'ID of function to execute.', false, ['dbForProject'])
            ->param('branch', '', new Text(255, 0), 'Name of VCS branch to deploy changes automatically.', true)
            ->inject('response')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('platform')
            ->inject('log')
            ->inject('authorization')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(
        string $domain,
        string $functionId,
        string $branch,
        Response $response,
        Document $project,
        Event $queueForEvents,
        Database $dbForPlatform,
        Database $dbForProject,
        array $platform,
        Log $log,
        Authorization $authorization,
        Bus $bus,
    ): void {
        $domain = \strtolower($domain);
        $appwriteOwned = $this->isAppwriteOwned($domain);
        if (! $appwriteOwned) {
            $this->validateDomainRestrictions($domain, $platform);
        }

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        if ($appwriteOwned && ! $this->isGeneratedDomainForFunction($domain, $functionId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Generated SMTP domain does not belong to this function.');
        }

        $deployment = $dbForProject->getDocument('deployments', $function->getAttribute('deploymentId', ''));
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5'
            ? md5('smtp:'.$domain)
            : ID::unique();
        $verificationToken = \bin2hex(\random_bytes(16));

        $rule = new Document([
            '$id' => $ruleId,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'domain' => $domain,
            'protocol' => 'smtp',
            'verificationToken' => $verificationToken,
            'status' => $appwriteOwned ? RULE_STATUS_VERIFIED : RULE_STATUS_CREATED,
            'type' => 'deployment',
            'trigger' => 'manual',
            'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
            'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
            'deploymentResourceType' => 'function',
            'deploymentResourceId' => $function->getId(),
            'deploymentResourceInternalId' => $function->getSequence(),
            'deploymentVcsProviderBranch' => $branch,
            'certificateId' => '',
            'search' => \implode(' ', [$ruleId, $domain, $branch]),
            'owner' => $appwriteOwned ? 'Appwrite' : '',
            'region' => $project->getAttribute('region'),
        ]);

        if (! $appwriteOwned) {
            try {
                $this->verifySmtpRule($rule, $log);
                $rule->setAttribute('status', RULE_STATUS_VERIFIED);
            } catch (Exception $err) {
                $rule->setAttribute('logs', $err->getMessage());
            }
        }

        $rule = $this->createRule($rule, $dbForPlatform, $authorization, $bus);
        $queueForEvents->setParam('ruleId', $rule->getId());

        if ($rule->getAttribute('status') === RULE_STATUS_CREATED) {
            $rule->setAttribute('status', 'unverified');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    }

    private function isGeneratedDomainForFunction(string $domain, string $functionId): bool
    {
        foreach (\explode(',', System::getEnv('_APP_DOMAIN_FUNCTIONS', '')) as $suffix) {
            $suffix = \strtolower(\trim($suffix));
            if ($suffix !== '' && $domain === \strtolower($functionId).'.'.$suffix) {
                return true;
            }
        }

        return false;
    }
}
