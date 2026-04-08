<?php

namespace Appwrite\Platform\Modules\Console\Http\Assistant;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAssistantQuery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/console/assistant')
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
            ->callback($this->action(...));
    }

    public function action(string $prompt, Response $response)
    {
        $ch = curl_init('http://appwrite-assistant:3003/v1/models/assistant/prompt');
        if ($ch === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Assistant service is currently unavailable.');
        }

        $responseHeaders = [];
        $statusCode = 0;
        $query = json_encode(['prompt' => $prompt]);
        if ($query === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to process assistant request.');
        }

        $headers = [
            'accept: text/event-stream',
            'content-type: application/json',
        ];

        $handleEvent = function ($ch, $data) use ($response, &$statusCode) {
            // For failed upstream requests, consume response to let cURL complete,
            // but do not stream upstream error bodies to clients.
            if ($statusCode >= 400) {
                return \strlen($data);
            }

            $response->chunk($data);

            return \strlen($data);
        };

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $handleEvent);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9000);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders, &$statusCode) {
            $len = strlen($header);

            if (str_starts_with($header, 'HTTP/')) {
                $parts = explode(' ', trim($header));
                if (isset($parts[1])) {
                    $statusCode = (int) $parts[1];
                }

                return $len;
            }

            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

        $result = false;
        $error = '';
        try {
            $result = curl_exec($ch);
            $error = curl_error($ch);

            if ($statusCode === 0) {
                $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            }
        } finally {
            curl_close($ch);
        }

        if ($result === false || !empty($error)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Assistant service is currently unavailable.');
        }

        if ($statusCode >= 400) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Assistant service is currently unavailable.');
        }

        $response->chunk('', true);
    }
}
