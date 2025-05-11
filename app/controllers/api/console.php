<?php

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\System\System;
use Utopia\Validator\Text;

App::init()
    ->groups(['console'])
    ->inject('project')
    ->action(function (Document $project) {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    });


App::get('/v1/console/variables')
    ->desc('Get variables')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'console',
        group: 'console',
        name: 'variables',
        description: '/docs/references/console/variables.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_CONSOLE_VARIABLES,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->inject('response')
    ->action(function (Response $response) {
        $isDomainEnabled = !empty(System::getEnv('_APP_DOMAIN', ''))
            && !empty(System::getEnv('_APP_DOMAIN_TARGET', ''))
            && System::getEnv('_APP_DOMAIN', '') !== 'localhost'
            && System::getEnv('_APP_DOMAIN_TARGET', '') !== 'localhost';

        $isVcsEnabled = !empty(System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_APP_ID', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''));

        $isAssistantEnabled = !empty(System::getEnv('_APP_ASSISTANT_OPENAI_API_KEY', ''));

        $variables = new Document([
            '_APP_DOMAIN_TARGET' => System::getEnv('_APP_DOMAIN_TARGET'),
            '_APP_STORAGE_LIMIT' => +System::getEnv('_APP_STORAGE_LIMIT'),
            '_APP_FUNCTIONS_SIZE_LIMIT' => +System::getEnv('_APP_FUNCTIONS_SIZE_LIMIT'),
            '_APP_USAGE_STATS' => System::getEnv('_APP_USAGE_STATS'),
            '_APP_VCS_ENABLED' => $isVcsEnabled,
            '_APP_DOMAIN_ENABLED' => $isDomainEnabled,
            '_APP_ASSISTANT_ENABLED' => $isAssistantEnabled
        ]);

        $response->dynamic($variables, Response::MODEL_CONSOLE_VARIABLES);
    });

App::post('/v1/console/assistant')
    ->desc('Create assistant query')
    ->groups(['api', 'assistant'])
    ->label('scope', 'assistant.read')
    ->label('sdk', new Method(
        namespace: 'assistant',
        group: 'console',
        name: 'chat',
        description: '/docs/references/assistant/chat.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::TEXT
    ))
    ->label('abuse-limit', 15)
    ->label('abuse-key', 'userId:{userId}')
    ->param('prompt', '', new Text(2000), 'Prompt. A string containing questions asked to the AI assistant.')
    ->inject('response')
    ->action(function (string $prompt, Response $response) {
        $ch = curl_init('http://appwrite-assistant:3003/v1/models/assistant/prompt');
        $responseHeaders = [];
        $query = json_encode(['prompt' => $prompt]);
        $headers = ['accept: text/event-stream'];
        $handleEvent = function ($ch, $data) use ($response) {
            $response->chunk($data);

            return \strlen($data);
        };

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $handleEvent);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9000);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

        curl_exec($ch);

        curl_close($ch);

        $response->chunk('', true);
    });
