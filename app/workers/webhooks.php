<?php

use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;

require_once __DIR__ . '/../workers.php';

Console::title('Webhooks V1 Worker');
Console::success(APP_NAME . ' webhooks worker v1 has started');

class WebhooksV1 extends Worker
{
    public function init(): void
    {
    }

    public function run(): void
    {
        $errors = [];

        // Event
        $projectId = $this->args['projectId'] ?? '';
        $webhooks = $this->args['webhooks'] ?? [];
        $userId = $this->args['userId'] ?? '';
        $event = $this->args['event'] ?? '';
        $eventData = \json_encode($this->args['eventData']);

        foreach ($webhooks as $webhook) {
            if (!(isset($webhook['events']) && \is_array($webhook['events']) && \in_array($event, $webhook['events']))) {
                continue;
            }

            $id = $webhook['$id'] ?? '';
            $name = $webhook['name'] ?? '';
            $signature = $webhook['signature'] ?? 'not-yet-implemented';
            $url = $webhook['url'] ?? '';
            $security = (bool) ($webhook['security'] ?? true);
            $httpUser = $webhook['httpUser'] ?? null;
            $httpPass = $webhook['httpPass'] ?? null;

            $ch = \curl_init($url);

            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $eventData);
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
                    'Content-Length: ' . \strlen($eventData),
                    'X-' . APP_NAME . '-Webhook-Id: ' . $id,
                    'X-' . APP_NAME . '-Webhook-Event: ' . $event,
                    'X-' . APP_NAME . '-Webhook-Name: ' . $name,
                    'X-' . APP_NAME . '-Webhook-User-Id: ' . $userId,
                    'X-' . APP_NAME . '-Webhook-Project-Id: ' . $projectId,
                    'X-' . APP_NAME . '-Webhook-Signature: ' . $signature,
                ]
            );

            if (!$security) {
                \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }

            if (!empty($httpUser) && !empty($httpPass)) {
                \curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
                \curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            }

            if (false === \curl_exec($ch)) {
                $errors[] = \curl_error($ch) . ' in event ' . $event . ' for webhook ' . $name;
            }

            \curl_close($ch);
        }

        if (!empty($errors)) {
            throw new Exception(\implode(" / \n\n", $errors));
        }
    }

    public function shutdown(): void
    {
    }
}
