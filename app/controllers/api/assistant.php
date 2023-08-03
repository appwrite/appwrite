<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\Validator\Text;

App::init()
    ->groups(['console'])
    ->inject('project')
    ->action(function (Document $project) {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    });


App::post('/v1/assistant/chat')
    ->desc('Ask Query')
    ->groups(['api', 'assistant'])
    ->label('scope', 'public')
    // ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'assistant')
    ->label('sdk.method', 'chat')
    ->label('sdk.description', '/docs/references/assistant/chat.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_CONSOLE_VARIABLES)
    ->param('query', '', new Text(2000), 'Query')
    ->inject('response')
    ->action(function (string $query, Response $response) {
        $ch = curl_init('http://appwrite-assistant:3003/');
        $responseHeaders = [];
        $responseStatus = -1;
        $responseBody = '';
        $responseType = '';
        $query = json_encode(['prompt' => $query]);

        $headers = ['accept: text/event-stream'];
        $handleEvent = function($ch, $data) {
            var_dump($data);
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

        $responseBody   = curl_exec($ch);

        curl_close($ch);

        $response->send('Response ended');

    });
