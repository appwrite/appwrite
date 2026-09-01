<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Proxy\Http\SMTP\RecipientResolutions;

use Appwrite\Platform\Action;
use Appwrite\Smtp\Gateway;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

final class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSMTPRecipientResolution';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/internal/v1/smtp/recipient-resolutions')
            ->groups(['api'])
            ->label('scope', 'public')
            ->param('recipient', '', new Text(254), 'SMTP envelope recipient.')
            ->param('mailFrom', '', new Text(254), 'SMTP envelope sender.', true)
            ->param('remoteIp', '', new Text(45), 'Remote SMTP peer address.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $recipient,
        string $mailFrom,
        string $remoteIp,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        if (! Gateway::authorized($request)) {
            $response->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED)->json(['error' => 'Invalid SMTP gateway credential.']);

            return;
        }

        $separator = strrpos($recipient, '@');
        if ($separator === false || $separator === 0 || $separator === strlen($recipient) - 1) {
            $response->setStatusCode(Response::STATUS_CODE_UNPROCESSABLE_ENTITY)->json(['error' => 'Invalid SMTP recipient.']);

            return;
        }
        $domain = strtolower(substr($recipient, $separator + 1));

        $rules = $authorization->skip(fn () => $dbForPlatform->find('rules', [
            Query::equal('domain', [$domain]),
            Query::equal('protocol', ['smtp']),
            Query::limit(2),
        ]));
        $rule = count($rules) === 1 ? $rules[0] : new Document();
        if ($rule->isEmpty()
            || $rule->getAttribute('status') !== RULE_STATUS_VERIFIED
            || $rule->getAttribute('deploymentResourceType') !== 'function') {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND)->json(['error' => 'SMTP recipient not found.']);

            return;
        }

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $rule->getAttribute('projectId', '')));
        if ($project->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND)->json(['error' => 'SMTP recipient not found.']);

            return;
        }

        /** @var Database $dbForProject */
        $dbForProject = $getProjectDB($project);
        $functionId = $rule->getAttribute('deploymentResourceId', '');
        $function = $authorization->skip(fn () => $dbForProject->getDocument('functions', $functionId));
        $deploymentId = $rule->getAttribute('deploymentId', '');
        $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
        if ($function->isEmpty()
            || ! $function->getAttribute('enabled', false)
            || $deployment->isEmpty()
            || $deployment->getAttribute('status') !== 'ready'
            || $deployment->getAttribute('resourceId') !== $function->getId()) {
            $response->setStatusCode(Response::STATUS_CODE_UNPROCESSABLE_ENTITY)->json(['error' => 'SMTP recipient is not executable.']);

            return;
        }

        $now = time();
        $tokens = Gateway::recipientTokens();
        $token = $tokens->issue([
            'recipient' => strtolower($recipient),
            'domain' => $domain,
            'ruleId' => $rule->getId(),
            'projectId' => $project->getId(),
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId(),
        ], $now);

        $response->json([
            'token' => $token,
            'expiresAt' => gmdate(DATE_RFC3339, $tokens->expiresAt($now)),
        ]);
    }
}
