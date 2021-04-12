<?php

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Resque\Worker;

require_once __DIR__.'/../workers.php';

Console::title('Webhooks V1 Worker');

Console::success(APP_NAME.' webhooks worker v1 has started');

class WebhooksV1 extends Worker
{
    public $args = [];

    public function init(): void
    {
    }

    public function run(): void
    {
        global $register;

        $consoleDB = new Database();
        $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $consoleDB->setNamespace('app_console'); // Main DB
        $consoleDB->setMocks(Config::getParam('collections', []));
    
        $errors = [];

        // Event
        $projectId = $this->args['projectId'] ?? '';
        $userId = $this->args['userId'] ?? '';
        $event = $this->args['event'] ?? '';
        $eventData = \json_encode($this->args['eventData']);

        // Webhook

        Authorization::disable();

        $project = $consoleDB->getDocument($projectId);

        Authorization::reset();

        if (\is_null($project->getId()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) {
            throw new Exception('Project Not Found');
        }

        foreach ($project->getAttribute('webhooks', []) as $webhook) {
            if (!(isset($webhook['events']) && \is_array($webhook['events']) && \in_array($event, $webhook['events']))) {
                continue;
            }

            $id = $webhook['$id'] ?? '';
            $name = $webhook['name'] ?? '';
            $signature = $webhook['signature'] ?? 'not-yet-implemented';
            $url = $webhook['url'] ?? '';
            $security = (bool) $webhook['security'] ?? true;
            $httpUser = $webhook['httpUser'] ?? null;
            $httpPass = $webhook['httpPass'] ?? null;

            $ch = \curl_init($url);

            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $eventData);
            \curl_setopt($ch, CURLOPT_HEADER, 0);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($ch, CURLOPT_USERAGENT, \sprintf(APP_USERAGENT,
                App::getEnv('_APP_VERSION', 'UNKNOWN'),
                App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
            ));
            \curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Content-Type: application/json',
                    'Content-Length: '.\strlen($eventData),
                    'X-'.APP_NAME.'-Webhook-Id: '.$id,
                    'X-'.APP_NAME.'-Webhook-Event: '.$event,
                    'X-'.APP_NAME.'-Webhook-Name: '.$name,
                    'X-'.APP_NAME.'-Webhook-User-Id: '.$userId,
                    'X-'.APP_NAME.'-Webhook-Project-Id: '.$projectId,
                    'X-'.APP_NAME.'-Webhook-Signature: '.$signature,
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
                $errors[] = \curl_error($ch).' in event '.$event.' for webhook '.$name;
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
