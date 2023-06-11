<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Webhooks extends Action
{
    private $errors = [];

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
            ->callback(fn($message) => $this->action($message));
    }

    /**
     * @throws Exception
     */
    public function action(Message $message): void
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
            if (array_intersect($webhook->getAttribute('events', []), $events)) {
                    $this->execute($events, $webhookPayload, $webhook, $user, $project);
            }
        }

        if (!empty($errors)) {
            throw new Exception(\implode(" / \n\n", $errors));
        }

            $this->errors = [];
    }


    private function execute(array $events, string $payload, Document $webhook, Document $user, Document $project): void
    {

        $url = \rawurldecode($webhook->getAttribute('url'));
        $signatureKey = $webhook->getAttribute('signatureKey');
        $signature = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));
        $httpUser = $webhook->getAttribute('httpUser');
        $httpPass = $webhook->getAttribute('httpPass');
        $ch = \curl_init($webhook->getAttribute('url'));
        var_dump($url);
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
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

        if (!$webhook->getAttribute('security', true)) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        if (!empty($httpUser) && !empty($httpPass)) {
            \curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
            \curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        if (false === \curl_exec($ch)) {
            $this->errors[] = \curl_error($ch) . ' in events ' . implode(', ', $events) . ' for webhook ' . $webhook->getAttribute('name');
        }

        \curl_close($ch);
    }
}
