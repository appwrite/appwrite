<?php

namespace Appwrite\Platform\Modules\Console\Http\Assistant;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Request\Factory as RequestFactory;
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
        // No content type was ever declared, so curl sent its POST default
        $request = (new RequestFactory())->body(
            'POST',
            'http://appwrite-assistant:3003/v1/models/assistant/prompt',
            json_encode(['prompt' => $prompt]),
            'application/x-www-form-urlencoded',
            ['accept' => 'text/event-stream'],
        );

        // No Accept-Encoding: a compressing upstream would buffer the event stream
        $client = (new Client(new CurlAdapter(options: [CURLOPT_ENCODING => null])))
            ->withFollowRedirects()
            ->withConnectTimeout(0)
            ->withTimeout(9000);

        try {
            $client->stream($request, fn (string $data) => $response->chunk($data));
        } catch (ClientExceptionInterface) {
            // The stream simply ends; the client sees whatever arrived
        }

        $response->chunk('', true);
    }
}
