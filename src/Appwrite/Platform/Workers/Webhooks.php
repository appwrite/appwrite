<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Mail;
use Appwrite\Template\Template;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Webhooks extends Action
{
    private array $errors = [];
    private const MAX_FILE_SIZE = 5242880; // 5 MB

    public static function getName(): string
    {
        return 'webhooks';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Webhooks worker')
            ->inject('message')
            ->inject('dbForConsole')
            ->inject('queueForMails')
            ->inject('log')
            ->callback(fn (Message $message, Database $dbForConsole, Mail $queueForMails, Log $log) => $this->action($message, $dbForConsole, $queueForMails, $log));
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @param Mail $queueForMails
     * @param Log $log
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Database $dbForConsole, Mail $queueForMails, Log $log): void
    {
        $this->errors = [];
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events = $payload['events'];
        $webhookPayload = json_encode($payload['payload']);
        $project = new Document($payload['project']);
        $user = new Document($payload['user'] ?? []);

        $log->addTag('projectId', $project->getId());

        foreach ($project->getAttribute('webhooks', []) as $webhook) {
            if (array_intersect($webhook->getAttribute('events', []), $events)) {
                $this->execute($events, $webhookPayload, $webhook, $user, $project, $dbForConsole, $queueForMails);
            }
        }

        if (!empty($this->errors)) {
            throw new Exception(\implode(" / \n\n", $this->errors));
        }
    }

    /**
     * @param array $events
     * @param string $payload
     * @param Document $webhook
     * @param Document $user
     * @param Document $project
     * @param Database $dbForConsole
     * @param Mail $queueForMails
     * @return void
     */
    private function execute(array $events, string $payload, Document $webhook, Document $user, Document $project, Database $dbForConsole, Mail $queueForMails): void
    {
        if ($webhook->getAttribute('enabled') !== true) {
            return;
        }

        $url = \rawurldecode($webhook->getAttribute('url'));
        $signatureKey = $webhook->getAttribute('signatureKey');
        $signature = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));
        $httpUser = $webhook->getAttribute('httpUser');
        $httpPass = $webhook->getAttribute('httpPass');
        $ch = \curl_init($webhook->getAttribute('url'));

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        \curl_setopt($ch, CURLOPT_MAXFILESIZE, self::MAX_FILE_SIZE);
        \curl_setopt($ch, CURLOPT_USERAGENT, \sprintf(
            APP_USERAGENT,
            System::getEnv('_APP_VERSION', 'UNKNOWN'),
            System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
        ));
        \curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'Content-Length: ' . \strlen($payload),
                'X-' . APP_NAME . '-Webhook-Id: ' . $webhook->getId(),
                'X-' . APP_NAME . '-Webhook-Events: ' . implode(',', $events),
                'X-' . APP_NAME . '-Webhook-Name: ' . $webhook->getAttribute('name', ''),
                'X-' . APP_NAME . '-Webhook-User-Id: ' . $user->getId(),
                'X-' . APP_NAME . '-Webhook-Project-Id: ' . $project->getId(),
                'X-' . APP_NAME . '-Webhook-Signature: ' . $signature,
            ]
        );
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        if (!$webhook->getAttribute('security', true)) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        if (!empty($httpUser) && !empty($httpPass)) {
            \curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
            \curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $responseBody = \curl_exec($ch);
        $curlError = \curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        if (!empty($curlError) || $statusCode >= 400) {
            $dbForConsole->increaseDocumentAttribute('webhooks', $webhook->getId(), 'attempts', 1);
            $webhook = $dbForConsole->getDocument('webhooks', $webhook->getId());
            $attempts = $webhook->getAttribute('attempts');

            $logs = '';
            $logs .= 'URL: ' . $webhook->getAttribute('url') . "\n";
            $logs .= 'Method: ' . 'POST' . "\n";

            if (!empty($curlError)) {
                $logs .= 'CURL Error: ' . $curlError . "\n";
                $logs .= 'Events: ' . implode(', ', $events) . "\n";
            } else {
                $logs .= 'Status code: ' . $statusCode . "\n";
                $logs .= 'Body: ' . "\n" . \mb_strcut($responseBody, 0, 10000) . "\n"; // Limit to 10kb
            }

            $webhook->setAttribute('logs', $logs);

            if ($attempts >= \intval(System::getEnv('_APP_WEBHOOK_MAX_FAILED_ATTEMPTS', '10'))) {
                $webhook->setAttribute('enabled', false);
                $this->sendEmailAlert($attempts, $statusCode, $webhook, $project, $dbForConsole, $queueForMails);
            }

            $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
            $dbForConsole->purgeCachedDocument('projects', $project->getId());

            $this->errors[] = $logs;
        } else {
            $webhook->setAttribute('attempts', 0); // Reset attempts on success
            $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
            $dbForConsole->purgeCachedDocument('projects', $project->getId());
        }
    }

    /**
     * @param int $attempts
     * @param mixed $statusCode
     * @param Document $webhook
     * @param Document $project
     * @param Database $dbForConsole
     * @param Mail $queueForMails
     * @return void
     */
    public function sendEmailAlert(int $attempts, mixed $statusCode, Document $webhook, Document $project, Database $dbForConsole, Mail $queueForMails): void
    {
        $memberships = $dbForConsole->find('memberships', [
            Query::equal('teamInternalId', [$project->getAttribute('teamInternalId')]),
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        $userIds = array_column(\array_map(fn ($membership) => $membership->getArrayCopy(), $memberships), 'userId');

        $users = $dbForConsole->find('users', [
            Query::equal('$id', $userIds),
        ]);

        $projectId = $project->getId();
        $webhookId = $webhook->getId();

        $template = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-webhook-failed.tpl');

        $template->setParam('{{webhook}}', $webhook->getAttribute('name'));
        $template->setParam('{{project}}', $project->getAttribute('name'));
        $template->setParam('{{url}}', $webhook->getAttribute('url'));
        $template->setParam('{{error}}', $curlError ??  'The server returned ' . $statusCode . ' status code');
        $template->setParam('{{path}}', "/console/project-$projectId/settings/webhooks/$webhookId");
        $template->setParam('{{attempts}}', $attempts);

        // TODO: Use setbodyTemplate once #7307 is merged
        $subject = 'Webhook deliveries have been paused';
        $body = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl');

        $body
            ->setParam('{{subject}}', $subject)
            ->setParam('{{message}}', $template->render())
            ->setParam('{{year}}', date("Y"));

        $queueForMails
            ->setSubject($subject)
            ->setBody($body->render());

        foreach ($users as $user) {
            $queueForMails
                ->setVariables(['user' => $user->getAttribute('name', '')])
                ->setName($user->getAttribute('name', ''))
                ->setRecipient($user->getAttribute('email'))
                ->trigger();
        }
    }
}
