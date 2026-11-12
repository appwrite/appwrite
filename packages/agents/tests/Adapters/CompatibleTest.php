<?php

namespace Utopia\Agents\Tests\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\Deepseek;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Adapters\Perplexity;
use Utopia\Agents\Adapters\XAI;
use Utopia\Agents\Effort;
use Utopia\Agents\Message;
use Utopia\Agents\Request;
use Utopia\Agents\Tests\Fakes\Client;

/**
 * Providers that speak the OpenAI Chat Completions protocol.
 */
class CompatibleTest extends TestCase
{
    /**
     * @return array<string, array{class-string<OpenAI>, string, string}>
     */
    public static function providers(): array
    {
        return [
            'deepseek' => [Deepseek::class, 'deepseek', 'https://api.deepseek.com/chat/completions'],
            'xai' => [XAI::class, 'xai', 'https://api.x.ai/v1/chat/completions'],
            'perplexity' => [Perplexity::class, 'perplexity', 'https://api.perplexity.ai/chat/completions'],
            'openrouter' => [OpenRouter::class, 'openrouter', 'https://openrouter.ai/api/v1/chat/completions'],
        ];
    }

    /**
     * @param  class-string<OpenAI>  $class
     */
    #[DataProvider('providers')]
    public function testUsesProviderEndpointAndTokenLimit(string $class, string $name, string $endpoint): void
    {
        $client = (new Client())->events([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);
        $adapter = new $class('key', 'model-x', client: $client);

        $response = $adapter->generate(new Request([Message::user('Hi')], maxTokens: 10));

        $this->assertSame($name, $adapter->getName());
        $this->assertSame($endpoint, (string) $client->lastRequest()->getUri());
        $this->assertSame(10, $client->payload('max_tokens'));
        $this->assertSame('ok', $response->text());
    }

    public function testOpenRouterSendsAttributionAndReasoningObject(): void
    {
        $client = (new Client())->events([['choices' => [['delta' => ['reasoning' => 'think', 'content' => 'ok'], 'finish_reason' => 'stop']]]]);
        $adapter = new OpenRouter('key', 'anthropic/claude-sonnet-4.5', client: $client, referer: 'https://appwrite.io', title: 'Appwrite');

        $response = $adapter->generate(new Request([Message::user('Hi')], effort: Effort::High, options: ['openrouter' => ['reasoning' => ['exclude' => false]]]));

        $request = $client->lastRequest();
        $this->assertSame('https://appwrite.io', $request->getHeaderLine('HTTP-Referer'));
        $this->assertSame('Appwrite', $request->getHeaderLine('X-Title'));
        $this->assertSame(['effort' => 'high', 'exclude' => false], $client->payload('reasoning'));
        $this->assertArrayNotHasKey('reasoning_effort', $client->lastPayload());
        $this->assertSame('think', $response->message()->reasoning());
    }
}
