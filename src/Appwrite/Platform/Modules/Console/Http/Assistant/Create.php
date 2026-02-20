<?php

namespace Appwrite\Platform\Modules\Console\Http\Assistant;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    /**
     * Default model for OSS / unknown plan (fast, cheap).
     */
    private const MODEL_DEFAULT = 'claude-haiku-4-5-20251001';

    /**
     * Model for paid cloud plans (more capable).
     */
    private const MODEL_PRO = 'claude-sonnet-4-6';

    /**
     * Tool tiers:
     *   none      – docs Q&A only (OSS + Cloud Free)
     *   read      – read-only SDK tools (Cloud Pro+)
     *   readwrite – read + write SDK tools with approval gate (Cloud Scale+)
     */
    private const TOOL_TIER_NONE = 'none';
    private const TOOL_TIER_READ = 'read';
    private const TOOL_TIER_READWRITE = 'readwrite';

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
            ->param('messages', '[]', new Text(100000), 'Conversation history as a JSON array of CoreMessage objects. When provided, takes precedence over prompt.', true)
            ->param('context', '{}', new Text(5000), 'Console context as a JSON object (current page, projectId, orgId, plan, error).', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $prompt,
        string $messages,
        string $context,
        Response $response,
        Document $user,
        Database $dbForPlatform,
    ): void {
        $decodedContext = json_decode($context, true) ?? [];

        // orgId lives inside context (sent by the console frontend)
        $orgId = $decodedContext['orgId'] ?? '';

        [$model, $toolTier] = $this->resolveModelAndTier($orgId, $dbForPlatform);

        $traceId = \bin2hex(\random_bytes(16));
        $userId = $user->getId();

        // Build the messages array to forward.
        // If the caller supplied a full messages array, use it.
        // Otherwise build a single-turn from the legacy `prompt` field.
        $decodedMessages = json_decode($messages, true) ?? [];
        if (empty($decodedMessages) && $prompt !== '') {
            $decodedMessages = [
                ['role' => 'user', 'content' => $prompt],
            ];
        }

        if (empty($decodedMessages)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            return;
        }

        $payload = json_encode([
            'messages' => $decodedMessages,
            'context'  => $decodedContext,
            'model'    => $model,
            'toolTier' => $toolTier,
            'traceId'  => $traceId,
            'userId'   => $userId,
        ]);

        $assistantHost = System::getEnv('_APP_ASSISTANT_HOST', 'http://appwrite-assistant:3003');
        $ch = curl_init(\rtrim($assistantHost, '/') . '/v1/models/assistant/prompt');

        $responseHeaders = [];

        $handleChunk = function ($_ch, $data) use ($response): int {
            $response->chunk($data);
            return \strlen($data);
        };

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $handleChunk);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'X-Trace-Id: ' . $traceId,
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($_curl, $header) use (&$responseHeaders): int {
            $len = \strlen($header);
            $parts = \explode(':', $header, 2);

            if (\count($parts) < 2) {
                return $len;
            }

            $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);

            return $len;
        });

        curl_exec($ch);

        $response->chunk('', true);
    }

    /**
     * Resolve which Anthropic model and tool tier to use based on the org's billing plan.
     *
     * Falls back to the safe OSS baseline (haiku + no tools) when:
     *   - no orgId is provided (OSS / self-hosted)
     *   - the team document is not found
     *   - the plans config is absent (OSS build without cloud extension)
     *
     * @return array{string, string}  [$model, $toolTier]
     */
    private function resolveModelAndTier(string $orgId, Database $dbForPlatform): array
    {
        if ($orgId === '') {
            return [self::MODEL_DEFAULT, self::TOOL_TIER_NONE];
        }

        try {
            $team = $dbForPlatform->getDocument('teams', $orgId);
            if ($team->isEmpty()) {
                return [self::MODEL_DEFAULT, self::TOOL_TIER_NONE];
            }

            $planId   = $team->getAttribute('billingPlan', '');
            $plans    = Config::getParam('plans', []);
            $plan     = $plans[$planId] ?? [];
            $toolTier = $plan['aiChatbotTools'] ?? self::TOOL_TIER_NONE;

            return match ($toolTier) {
                self::TOOL_TIER_READ      => [self::MODEL_PRO, self::TOOL_TIER_READ],
                self::TOOL_TIER_READWRITE => [self::MODEL_PRO, self::TOOL_TIER_READWRITE],
                default                   => [self::MODEL_DEFAULT, self::TOOL_TIER_NONE],
            };
        } catch (\Throwable) {
            return [self::MODEL_DEFAULT, self::TOOL_TIER_NONE];
        }
    }
}
