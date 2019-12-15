<?php

global $utopia, $request, $response, $consoleDB, $project;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\URL;
use Database\Database;
use Database\Document;
use Database\Validator\UID;
use OpenSSL\OpenSSL;

include_once '../shared/api.php';

$utopia->get('/v1/webhooks')
    ->desc('List Webhooks')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listWebhooks')
    ->action(
        function () use ($request, $response, $consoleDB, $project) {
            $webhooks = $project->getAttribute('webhooks', []);

            foreach ($webhooks as $webhook) { /* @var $webhook Document */
                $httpPass = json_decode($webhook->getAttribute('httpPass', '{}'), true);

                if (empty($httpPass) || !isset($httpPass['version'])) {
                    continue;
                }

                $key = $request->getServer('_APP_OPENSSL_KEY_V'.$httpPass['version']);

                $webhook->setAttribute('httpPass', OpenSSL::decrypt($httpPass['data'], $httpPass['method'], $key, 0, hex2bin($httpPass['iv']), hex2bin($httpPass['tag'])));
            }

            $response->json($webhooks);
        }
    );

$utopia->get('/v1/webhooks/:webhookId')
    ->desc('Get Webhook')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getWebhook')
    ->param('webhookId', null, function () { return new UID(); }, 'Webhook unique ID.')
    ->action(
        function ($webhookId) use ($request, $response, $consoleDB, $project) {
            $webhook = $project->search('$uid', $webhookId, $project->getAttribute('webhooks', []));

            if (empty($webhook) && $webhook instanceof Document) {
                throw new Exception('Webhook not found', 404);
            }

            $httpPass = json_decode($webhook->getAttribute('httpPass', '{}'), true);

            if (!empty($httpPass) && isset($httpPass['version'])) {
                $key = $request->getServer('_APP_OPENSSL_KEY_V'.$httpPass['version']);
                $webhook->setAttribute('httpPass', OpenSSL::decrypt($httpPass['data'], $httpPass['method'], $key, 0, hex2bin($httpPass['iv']), hex2bin($httpPass['tag'])));
            }

            $response->json($webhook->getArrayCopy());
        }
    );

$utopia->post('/v1/webhooks')
    ->desc('Create Webhook')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createWebhook')
    ->param('name', null, function () { return new Text(256); }, 'Webhook name')
    ->param('events', null, function () { return new ArrayList(new Text(256)); }, 'Webhook events list')
    ->param('url', null, function () { return new Text(2000); }, 'Webhook URL')
    ->param('security', null, function () { return new Range(0, 1); }, 'Certificate verification, 0 for disabled or 1 for enabled')
    ->param('httpUser', '', function () { return new Text(256); }, 'Webhook HTTP user', true)
    ->param('httpPass', '', function () { return new Text(256); }, 'Webhook HTTP password', true)
    ->action(
        function ($name, $events, $url, $security, $httpUser, $httpPass) use ($request, $response, $consoleDB, $project) {
            $key = $request->getServer('_APP_OPENSSL_KEY_V1');
            $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
            $tag = null;
            $httpPass = json_encode([
                'data' => OpenSSL::encrypt($httpPass, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
                'method' => OpenSSL::CIPHER_AES_128_GCM,
                'iv' => bin2hex($iv),
                'tag' => bin2hex($tag),
                'version' => '1',
            ]);

            $webhook = $consoleDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_WEBHOOKS,
                '$permissions' => [
                    'read' => ['team:'.$project->getAttribute('teamId', null)],
                    'write' => ['team:'.$project->getAttribute('teamId', null).'/owner', 'team:'.$project->getAttribute('teamId', null).'/developer'],
                ],
                'name' => $name,
                'events' => $events,
                'url' => $url,
                'security' => (int) $security,
                'httpUser' => $httpUser,
                'httpPass' => $httpPass,
            ]);

            if (false === $webhook) {
                throw new Exception('Failed saving webhook to DB', 500);
            }

            $project->setAttribute('webhooks', $webhook, Document::SET_TYPE_APPEND);

            $project = $consoleDB->updateDocument($project->getArrayCopy());

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($webhook->getArrayCopy())
            ;
        }
    );

$utopia->put('/v1/webhooks/:webhookId')
    ->desc('Update Webhook')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateWebhook')
    ->param('webhookId', null, function () { return new UID(); }, 'Webhook unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Webhook name')
    ->param('events', null, function () { return new ArrayList(new Text(256)); }, 'Webhook events list')
    ->param('url', null, function () { return new Text(2000); }, 'Webhook URL')
    ->param('security', null, function () { return new Range(0, 1); }, 'Certificate verification, 0 for disabled or 1 for enabled')
    ->param('httpUser', '', function () { return new Text(256); }, 'Webhook HTTP user', true)
    ->param('httpPass', '', function () { return new Text(256); }, 'Webhook HTTP password', true)
    ->action(
        function ($webhookId, $name, $events, $url, $security, $httpUser, $httpPass) use ($request, $response, $consoleDB, $project) {
            $key = $request->getServer('_APP_OPENSSL_KEY_V1');
            $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
            $tag = null;
            $httpPass = json_encode([
                'data' => OpenSSL::encrypt($httpPass, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
                'method' => OpenSSL::CIPHER_AES_128_GCM,
                'iv' => bin2hex($iv),
                'tag' => bin2hex($tag),
                'version' => '1',
            ]);

            $webhook = $project->search('$uid', $webhookId, $project->getAttribute('webhooks', []));

            if (empty($webhook) && $webhook instanceof Document) {
                throw new Exception('Webhook not found', 404);
            }

            $webhook
                ->setAttribute('name', $name)
                ->setAttribute('events', $events)
                ->setAttribute('url', $url)
                ->setAttribute('security', (int) $security)
                ->setAttribute('httpUser', $httpUser)
                ->setAttribute('httpPass', $httpPass)
            ;

            if (false === $consoleDB->updateDocument($webhook->getArrayCopy())) {
                throw new Exception('Failed saving webhook to DB', 500);
            }

            $response->json($webhook->getArrayCopy());
        }
    );

$utopia->delete('/v1/webhooks/:webhookId')
    ->desc('Delete Webhook')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteWebhook')
    ->param('webhookId', null, function () { return new UID(); }, 'Webhook unique ID.')
    ->action(
        function ($webhookId) use ($response, $consoleDB, $project) {
            $webhook = $project->search('$uid', $webhookId, $project->getAttribute('webhooks', []));

            if (empty($webhook) && $webhook instanceof Document) {
                throw new Exception('Webhook not found', 404);
            }

            if (!$consoleDB->deleteDocument($webhook->getUid())) {
                throw new Exception('Failed to remove webhook from DB', 500);
            }

            $response->noContent();
        }
    );