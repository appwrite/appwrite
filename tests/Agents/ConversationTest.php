<?php

namespace Tests\Utopia\Agents;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapter;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

class ConversationTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVQYV2NgYAAAAAMAAWgmWQ0AAAAASUVORK5CYII=';

    private const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function testMessageAcceptsAttachmentsAndAssignsRole(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $agent = new Agent($adapter);
        $conversation = new Conversation($agent);
        $user = new User('user-1');

        $attachment = new Message(base64_decode(self::PNG_1X1));
        $conversation->message($user, new Message('What is in this image?'), [$attachment]);

        $messages = $conversation->getMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('What is in this image?', $messages[0]->getContent());
        $this->assertCount(1, $messages[0]->getAttachments());
        $this->assertSame('user', $messages[0]->getRole());
        $this->assertSame('user', $messages[0]->getAttachments()[0]->getRole());
    }

    public function testSendPassesAttachmentsToAdapter(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $agent = new Agent($adapter);
        $conversation = new Conversation($agent);
        $user = new User('user-2');

        $attachment = new Message(base64_decode(self::GIF_1X1));
        $conversation
            ->message($user, new Message('Analyze this file'), [$attachment])
            ->send();

        $this->assertNotNull($adapter->lastSentMessage);
        $this->assertInstanceOf(Message::class, $adapter->lastSentMessage);
        $this->assertCount(1, $adapter->lastSentMessage->getAttachments());
    }

    public function testRejectsTooManyAttachments(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $adapter->maxAttachmentsPerMessage = 1;
        $conversation = new Conversation(new Agent($adapter));
        $user = new User('user-3');

        $this->expectException(\InvalidArgumentException::class);
        $conversation->message($user, new Message('Analyze'), [
            new Message(base64_decode(self::PNG_1X1)),
            new Message(base64_decode(self::GIF_1X1)),
        ]);
    }

    public function testRejectsOversizedAttachment(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $adapter->maxAttachmentBytes = 4;
        $conversation = new Conversation(new Agent($adapter));
        $user = new User('user-4');

        $this->expectException(\InvalidArgumentException::class);
        $conversation->message($user, new Message('Analyze'), [
            new Message(base64_decode(self::PNG_1X1)),
        ]);
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $adapter->allowedAttachmentMimeTypes = ['image/jpeg'];
        $conversation = new Conversation(new Agent($adapter));
        $user = new User('user-5');

        $this->expectException(\InvalidArgumentException::class);
        $conversation->message($user, new Message('Analyze'), [
            new Message(base64_decode(self::PNG_1X1)),
        ]);
    }

    public function testRejectsUnsupportedAdapterAttachmentWhenCompatibilityChecksEnabled(): void
    {
        $adapter = new ConversationFakeAdapter('ok', false);
        $conversation = new Conversation(new Agent($adapter));
        $user = new User('user-6');

        $this->expectException(\InvalidArgumentException::class);
        $conversation->message($user, new Message('Analyze'), [
            new Message(base64_decode(self::PNG_1X1)),
        ]);
    }

    public function testAdapterCanRelaxLimits(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $adapter->maxAttachmentsPerMessage = null;
        $adapter->maxAttachmentBytes = null;
        $adapter->maxTotalAttachmentBytes = null;
        $adapter->allowedAttachmentMimeTypes = null;
        $conversation = new Conversation(new Agent($adapter));
        $user = new User('user-7');

        $conversation->message($user, new Message('Analyze'), [
            new Message(base64_decode(self::PNG_1X1)),
        ]);

        $this->assertCount(1, $conversation->getMessages()[0]->getAttachments());
    }

    public function testRejectsAttachmentsWhenTotalPayloadExceedsLimit(): void
    {
        $adapter = new ConversationFakeAdapter('ok');
        $adapter->maxTotalAttachmentBytes = 80;
        $conversation = new Conversation(new Agent($adapter));
        $user = new User('user-8');

        $this->expectException(\InvalidArgumentException::class);
        $conversation->message($user, new Message('Analyze'), [
            new Message(base64_decode(self::PNG_1X1)),
            new Message(base64_decode(self::PNG_1X1)),
        ]);
    }
}

class ConversationFakeAdapter extends Adapter
{
    public ?Message $lastSentMessage = null;

    public ?int $maxAttachmentsPerMessage = 10;

    public ?int $maxAttachmentBytes = 5000000;

    public ?int $maxTotalAttachmentBytes = 20000000;

    /**
     * @var list<string>|null
     */
    public ?array $allowedAttachmentMimeTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly string $response,
        private readonly bool $supportsImageAttachments = true
    ) {}

    public function supportsAttachments(): bool
    {
        return $this->supportsImageAttachments;
    }

    public function supportsAttachment(Message $attachment): bool
    {
        $mimeType = $attachment->getMimeType();

        return $this->supportsImageAttachments && $mimeType !== null && str_starts_with($mimeType, 'image/');
    }

    public function getMaxAttachmentsPerMessage(): ?int
    {
        return $this->maxAttachmentsPerMessage;
    }

    public function getMaxAttachmentBytes(): ?int
    {
        return $this->maxAttachmentBytes;
    }

    public function getMaxTotalAttachmentBytes(): ?int
    {
        return $this->maxTotalAttachmentBytes;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedAttachmentMimeTypes(): ?array
    {
        return $this->allowedAttachmentMimeTypes;
    }

    public function getName(): string
    {
        return 'conversation-fake';
    }

    /**
     * @param  array<Message>  $messages
     */
    public function send(array $messages, ?callable $listener = null): Message
    {
        $last = end($messages);
        $this->lastSentMessage = $last instanceof Message ? $last : null;

        return new Message($this->response);
    }

    public function getModels(): array
    {
        return ['fake-model'];
    }

    public function getModel(): string
    {
        return 'fake-model';
    }

    public function setModel(string $model): self
    {
        return $this;
    }

    public function isSchemaSupported(): bool
    {
        return false;
    }

    public function getSupportForEmbeddings(): bool
    {
        return false;
    }

    public function embed(string $text): array
    {
        throw new \Exception('Embeddings not supported');
    }

    /**
     * @param  array<int, string>  $texts
     * @return array{
     *     embeddings: array<int, array<int, float>>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null
     * }
     */
    public function bulkEmbed(array $texts): array
    {
        throw new \Exception('Embeddings not supported');
    }

    public function getEmbeddingDimension(): int
    {
        throw new \Exception('Embeddings not supported');
    }

    protected function formatErrorMessage($json): string
    {
        return 'fake error';
    }
}
