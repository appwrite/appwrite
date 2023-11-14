<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Webhooks extends Action
{
    private array $errors = [];

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
            ->callback(fn($message, Database $dbForConsole) => $this->action($message, $dbForConsole));
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Database $dbForConsole): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events = $payload['events'];
        $webhookPayload = json_encode($payload['payload']);
        $project = new Document($payload['project']);
        $user = new Document($payload['user'] ?? []);

        foreach ($project->getAttribute('webhooks', []) as $webhook) {
            if ($webhook->getAttribute('status') === true && array_intersect($webhook->getAttribute('events', []), $events)) {
                $this->execute($events, $webhookPayload, $webhook, $user, $project, $dbForConsole);
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
     * @return void
     */
    private function execute(array $events, string $payload, Document $webhook, Document $user, Document $project, Database $dbForConsole): void
    {
        if ($webhook->getAttribute('status') === false) {
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
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        \curl_setopt($ch, CURLOPT_MAXFILESIZE, 5242880);
        \curl_setopt($ch, CURLOPT_USERAGENT, \sprintf(
            APP_USERAGENT,
            App::getEnv('_APP_VERSION', 'UNKNOWN'),
            App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
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

        if (false === \curl_exec($ch)) {
            $errorCount = $webhook->getAttribute('errors', 0) + 1;
            $lastErrorLogs = \curl_error($ch) . ' in events ' . implode(', ', $events) . ' for webhook ' . $webhook->getAttribute('name');
            $webhook->setAttribute('errors', $errorCount);
            $webhook->setAttribute('logs', $lastErrorLogs);

            if ($errorCount > 9) {
                $webhook->setAttribute('status', false);
            }

            $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
            $dbForConsole->deleteCachedDocument('projects', $project->getId());

            $this->errors[] = $lastErrorLogs;
        }

        \curl_close($ch);
    }
}
