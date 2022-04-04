<?php

use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;

require_once __DIR__ . '/../init.php';

Console::title('Webhooks V1 Worker');
Console::success(APP_NAME . ' webhooks worker v1 has started');

class WebhooksV1 extends Worker
{
    public function getName(): string
    {
        return "webhooks";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $errors = [];

        // Event
        $events = $this->args['events'];
        $user = new Document($this->args['user']);
        $project = new Document($this->args['project']);
        $webhook = new Document($this->args['trigger'] ?? []);
        $payload = \json_encode($this->args['payload']);

        $httpUser = $webhook->getAttribute('httpUser');
        $httpPass = $webhook->getAttribute('httpPass');

        $ch = \curl_init($webhook->getAttribute('url'));

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
                'X-' . APP_NAME . '-Webhook-Event: ' . implode(', ', $events),
                'X-' . APP_NAME . '-Webhook-Name: ' . $webhook->getAttribute('name', ''),
                'X-' . APP_NAME . '-Webhook-User-Id: ' . $user->getId(),
                'X-' . APP_NAME . '-Webhook-Project-Id: ' . $project->getId(),
                'X-' . APP_NAME . '-Webhook-Signature: ' . $webhook->getAttribute('signature') ?? 'not-yet-implemented',
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
            $errors[] = \curl_error($ch) . ' in events ' . implode(', ', $events) . ' for webhook ' . $webhook->getAttribute('name');
        }

        \curl_close($ch);

        if (!empty($errors)) {
            throw new Exception(\implode(" / \n\n", $errors));
        }
    }

    public function shutdown(): void
    {
    }
}
