<?php

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\Domains\Domain;
use Utopia\System\System;
use Utopia\Validator\IP;
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
        $validator = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME'));
        $isCNAMEValid = !empty(System::getEnv('_APP_DOMAIN_TARGET_CNAME', '')) && $validator->isKnown() && !$validator->isTest();

        $validator = new IP(IP::V4);
        $isAValid = !empty(System::getEnv('_APP_DOMAIN_TARGET_A', '')) && ($validator->isValid(System::getEnv('_APP_DOMAIN_TARGET_A')));

        $validator = new IP(IP::V6);
        $isAAAAValid = !empty(System::getEnv('_APP_DOMAIN_TARGET_AAAA', '')) && $validator->isValid(System::getEnv('_APP_DOMAIN_TARGET_AAAA'));

        $isDomainEnabled = $isAAAAValid || $isAValid || $isCNAMEValid;

        $isVcsEnabled = !empty(System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_APP_ID', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''))
            && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''));

        $isAssistantEnabled = !empty(System::getEnv('_APP_ASSISTANT_OPENAI_API_KEY', ''));

        $variables = new Document([
            '_APP_DOMAIN_TARGET_CNAME' => System::getEnv('_APP_DOMAIN_TARGET_CNAME'),
            '_APP_DOMAIN_TARGET_AAAA' => System::getEnv('_APP_DOMAIN_TARGET_AAAA'),
            '_APP_DOMAIN_TARGET_A' => System::getEnv('_APP_DOMAIN_TARGET_A'),
            // Combine CAA domain with most common flags and tag (no parameters)
            '_APP_DOMAIN_TARGET_CAA' => '0 issue "' . System::getEnv('_APP_DOMAIN_TARGET_CAA') . '"',
            '_APP_STORAGE_LIMIT' => +System::getEnv('_APP_STORAGE_LIMIT'),
            '_APP_COMPUTE_BUILD_TIMEOUT' => +System::getEnv('_APP_COMPUTE_BUILD_TIMEOUT'),
            '_APP_COMPUTE_SIZE_LIMIT' => +System::getEnv('_APP_COMPUTE_SIZE_LIMIT'),
            '_APP_USAGE_STATS' => System::getEnv('_APP_USAGE_STATS'),
            '_APP_VCS_ENABLED' => $isVcsEnabled,
            '_APP_DOMAIN_ENABLED' => $isDomainEnabled,
            '_APP_ASSISTANT_ENABLED' => $isAssistantEnabled,
            '_APP_DOMAIN_SITES' => System::getEnv('_APP_DOMAIN_SITES'),
            '_APP_DOMAIN_FUNCTIONS' => System::getEnv('_APP_DOMAIN_FUNCTIONS'),
            '_APP_OPTIONS_FORCE_HTTPS' => System::getEnv('_APP_OPTIONS_FORCE_HTTPS'),
            '_APP_DOMAINS_NAMESERVERS' => System::getEnv('_APP_DOMAINS_NAMESERVERS'),
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
