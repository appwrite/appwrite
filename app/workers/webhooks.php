<?php

require_once __DIR__.'/../init.php';

cli_set_process_title('Webhooks V1 Worker');

echo APP_NAME.' webhooks worker v1 has started';

use Database\Database;
use Database\Validator\Authorization;

class WebhooksV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $consoleDB, $version;

        $errors = [];

        // Event
        $projectId = $this->args['projectId'];
        $event = $this->args['event'];
        $payload = json_encode($this->args['payload']);

        // Webhook

        Authorization::disable();

        $project = $consoleDB->getDocument($projectId);

        Authorization::enable();

        if (is_null($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) {
            throw new Exception('Project Not Found');
        }

        foreach ($project->getAttribute('webhooks', []) as $webhook) {
            if (!(isset($webhook['events']) && is_array($webhook['events']) && in_array($event, $webhook['events']))) {
                continue;
            }

            $name = (isset($webhook['name'])) ? $webhook['name'] : '';
            $signature = (isset($webhook['signature'])) ? $webhook['signature'] : 'not-yet-implemented';
            $url = (isset($webhook['url'])) ? $webhook['url'] : '';
            $security = (isset($webhook['security'])) ? (bool) $webhook['security'] : true;
            $httpUser = (isset($webhook['httpUser'])) ? $webhook['httpUser'] : null;
            $httpPass = (isset($webhook['httpPass'])) ? $webhook['httpPass'] : null;

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, sprintf(APP_USERAGENT, $version));
            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Content-Type: application/json',
                    'Content-Length: '.strlen($payload),
                    'X-'.APP_NAME.'-Event: '.$event,
                    'X-'.APP_NAME.'-Webhook-Name: '.$name,
                    'X-'.APP_NAME.'-Webhook-Signature: '.$signature,
                ]
            );

            if (!$security) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }

            if (!empty($httpUser) && !empty($httpPass)) {
                curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            }

            if (false === curl_exec($ch)) {
                $errors[] = curl_error($ch).' in event '.$event.' for webhook '.$name;
            }

            curl_close($ch);
        }

        if (!empty($errors)) {
            throw new Exception(implode(" / \n\n", $errors));
        }
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
