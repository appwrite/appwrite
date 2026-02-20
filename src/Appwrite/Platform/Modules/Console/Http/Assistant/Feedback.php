<?php

namespace Appwrite\Platform\Modules\Console\Http\Assistant;

use Appwrite\Utopia\Response;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Text;

class Feedback extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAssistantFeedback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/console/assistant/feedback')
            ->desc('Submit feedback score for an assistant response')
            ->groups(['api', 'assistant'])
            ->label('scope', 'assistant.read')
            ->label('abuse-limit', 60)
            ->label('abuse-key', 'userId:{userId}')
            ->param('traceId', '', new Text(64), 'Trace ID returned with the assistant response.')
            ->param('score', 0, new FloatValidator(), 'Feedback score. Use 1 for positive, 0 for negative.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(
        string $traceId,
        float $score,
        Response $response,
    ): void {
        $payload = json_encode([
            'traceId' => $traceId,
            'score'   => $score,
        ]);

        $assistantHost = System::getEnv('_APP_ASSISTANT_HOST', 'http://appwrite-assistant:3003');
        $ch = curl_init(\rtrim($assistantHost, '/') . '/v1/feedback/score');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        curl_exec($ch);

        $response->noContent();
    }
}
