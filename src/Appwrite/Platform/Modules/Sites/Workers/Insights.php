<?php

namespace Appwrite\Platform\Modules\Sites\Workers;

use Exception;
use Executor\Executor;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Fetch\Client;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;

class Insights extends Action
{
    protected Client $client;
    protected Executor $executor;

    public static function getName(): string
    {
        return 'insights';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->client = new Client();
        $this->client->addHeader('Authorization', 'Bearer 123');
        $this
            ->desc('Builds worker')
            ->inject('message')
            ->inject('dbForConsole')
            ->inject('dbForProject')
            ->inject('deviceForFunctions')
            ->inject('log')
            ->callback([$this, 'action']);
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @param Device $deviceForFunctions
     * @param Log $log
     * @return void
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, Database $dbForConsole, Database $dbForProject, Device $deviceForFunctions, Log $log): void
    {
        $payload = $message->getPayload() ?? [];
        $this->executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        //todo: handle preview deployments
        $resource = new Document($payload['resource'] ?? []);
        $deployment = new Document($payload['deployment'] ?? []);
        $rule = $dbForConsole->findOne('rules', [
            Query::equal('resourceType', ['site']),
            Query::equal('resourceInternalId', [$resource->getInternalId()]),
        ]);

        if (!$rule) {
            throw new \Exception('No rule found');
        }

        $url = "http://{$rule->getAttribute('domain')}";
        $screenshot = $this->getScreenshot($url);
        // todo: figure out where to store the screenshot
        $path = $deviceForFunctions->getRoot() . DIRECTORY_SEPARATOR . "screenshots" .DIRECTORY_SEPARATOR. "{$resource->getId()}.{$deployment->getId()}.png";
        $success = $deviceForFunctions->write($path, $screenshot, "image/png");
        if ($success) {
            $deployment->setAttribute('screenshot', $path);
            $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
        }
        $audit = $this->getAudit($url);
        if ($audit) {
            $deployment->setAttribute('auditJson', $audit);
            $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment);
        }
    }

    protected function getScreenshot(string $url): mixed|false
    {
        $response = $this->client->fetch('http://appwrite-browser/screenshot', query: [
            'url' => $url
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return $response->getBody();
    }

    protected function getAudit(string $url): string|false
    {
        $response = $this->client->fetch('http://appwrite-browser/lighthouse', query: [
            'url' => $url
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return $response->text();

    }
}
