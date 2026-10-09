<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Audit as AuditMessage;
use Exception;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;

class Audits extends Action
{
    public static function getName(): string
    {
        return 'audits';
    }

    public function __construct()
    {
        $this
            ->desc('Audits worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    /**
     * @throws Exception
     */
    public function action(Message $message, Document $project, Database $dbForProject): void
    {
        $payload = $message->getPayload();

        if ($payload === []) {
            throw new Exception('Missing payload');
        }

        if ($project->isEmpty()) {
            Console::warning('Skipping audit write: project not found');
            return;
        }

        $audit = new Audit(new AdapterDatabase($dbForProject));
        $audit->logBatch([$this->event(AuditMessage::fromArray($payload), $message)]);

        Span::add('audits.count', 1);
    }

    /**
     * @return array{userId: string, event: string, resource: string, userAgent: string, ip: string, time: string, data: array<string, mixed>}
     */
    private function event(AuditMessage $auditMessage, Message $message): array
    {
        $auditPayload = $auditMessage->project->getId() === 'console' ? $auditMessage->payload : '';
        $user = $auditMessage->user;
        $impersonatorUser = $auditMessage->impersonatorUser;
        $isImpersonated = !$impersonatorUser->isEmpty();
        $actor = $isImpersonated ? $impersonatorUser : $user;

        $data = [
            'userId' => $actor->getId(),
            'userName' => $actor->getAttribute('name', ''),
            'userEmail' => $actor->getAttribute('email', ''),
            'userType' => $actor->getAttribute('type', ACTOR_TYPE_USER),
            'mode' => $auditMessage->mode,
            'data' => $auditPayload,
        ];

        if ($isImpersonated) {
            $impersonated = [
                'impersonatedUserId' => $user->getId(),
                'impersonatedUserName' => $user->getAttribute('name', ''),
                'impersonatedUserEmail' => $user->getAttribute('email', ''),
            ];
            $data['data'] = \is_array($auditPayload)
                ? \array_merge($auditPayload, $impersonated)
                : ['payload' => $auditPayload, ...$impersonated];
        }

        return [
            'userId' => (string) $actor->getSequence(),
            'event' => $auditMessage->event,
            'resource' => $auditMessage->resource,
            'userAgent' => $auditMessage->userAgent,
            'ip' => $auditMessage->ip,
            'time' => \date('Y-m-d H:i:s', $message->getTimestamp()),
            'data' => $data,
        ];
    }
}
