<?php

namespace Utopia\Messaging\Adapter\Chat;

use InvalidArgumentException;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Messages\Discord as DiscordMessage;
use Utopia\Messaging\Response;

class Discord extends Adapter
{
    protected const NAME = 'Discord';
    protected const TYPE = 'chat';
    protected const MESSAGE_TYPE = DiscordMessage::class;
    protected string $webhookId = '';

    /**
     * @param  string  $webhookURL Your Discord webhook URL.
     * @throws InvalidArgumentException When webhook URL is invalid
     */
    public function __construct(
        private readonly string $webhookURL,
    ) {
        parent::__construct();
        // Validate URL format
        if (!filter_var($webhookURL, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid Discord webhook URL format.');
        }

        // Validate URL uses https scheme
        $urlParts = parse_url($webhookURL);
        if (!isset($urlParts['scheme']) || $urlParts['scheme'] !== 'https') {
            throw new InvalidArgumentException('Discord webhook URL must use HTTPS scheme.');
        }

        // Validate host is discord.com
        if (!isset($urlParts['host']) || $urlParts['host'] !== 'discord.com') {
            throw new InvalidArgumentException('Discord webhook URL must use discord.com as host.');
        }

        // Extract and validate webhook ID
        $parts = explode('/webhooks/', $urlParts['path']);
        if (\count($parts) >= 2) {
            $webhookParts = explode('/', $parts[1]);
            $this->webhookId = $webhookParts[0];
        }
        if ($this->webhookId === '' || $this->webhookId === '0') {
            throw new InvalidArgumentException('Discord webhook ID cannot be empty.');
        }
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getMessageType(): string
    {
        return static::MESSAGE_TYPE;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1;
    }

    /**
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     *
     * @throws \Exception
     */
    protected function process(DiscordMessage $message): array
    {
        $query = [];

        if (!\is_null($message->getWait())) {
            $query['wait'] = $message->getWait();
        }
        if (!\is_null($message->getThreadId())) {
            $query['thread_id'] = $message->getThreadId();
        }

        $queryString = $query === [] ? '' : '?' . http_build_query($query);

        $response = new Response($this->getType());
        $result = $this->request(
            method: 'POST',
            url: "{$this->webhookURL}{$queryString}",
            headers: [
                'Content-Type: application/json',
            ],
            body: [
                'content' => $message->getContent(),
                'username' => $message->getUsername(),
                'avatar_url' => $message->getAvatarUrl(),
                'tts' => $message->getTTS(),
                'embeds' => $message->getEmbeds(),
                'allowed_mentions' => $message->getAllowedMentions(),
                'components' => $message->getComponents(),
                'attachments' => $message->getAttachments(),
                'flags' => $message->getFlags(),
                'thread_name' => $message->getThreadName(),
            ],
        );

        $statusCode = $result['statusCode'];

        if ($statusCode >= 200 && $statusCode < 300) {
            $response->setDeliveredTo(1);
            $response->addResult($this->webhookId);
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $response->addResult($this->webhookId, 'Bad Request.');
        } else {
            $response->addResult($this->webhookId, 'Unknown Error.');
        }

        return $response->toArray();
    }
}
