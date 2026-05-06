<?php

namespace Appwrite\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Message;
use Utopia\Messaging\Response;

class Console extends Adapter
{
    protected const NAME = 'Console';
    protected const TYPE = 'console';
    protected const MESSAGE_TYPE = ConsoleMessage::class;

    public function __construct(protected Database $database)
    {
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
        return 1000;
    }

    public function send(Message $message): array
    {
        if (!$message instanceof ConsoleMessage) {
            throw new \Exception('Invalid message type.');
        }

        return $this->process($message);
    }

    protected function process(ConsoleMessage $message): array
    {
        $response = new Response($this->getType());
        $delivered = 0;

        foreach ($message->getRecipients() as $recipient) {
            $userId = $recipient['userId'] ?? '';
            $teamId = $recipient['teamId'] ?? '';
            $key = $userId !== '' ? $userId : $teamId;

            $messageId = $message->getMessageId();
            $recipientKey = $userId !== '' ? 'user:' . $userId : 'team:' . $teamId;
            $documentId = $messageId !== null
                ? $messageId . '_' . \substr(\md5($recipientKey), 0, 8)
                : ID::unique();

            try {
                $document = new Document([
                    '$id' => $documentId,
                    '$permissions' => $this->buildPermissions($userId, $teamId),
                    'messageId' => $messageId,
                    'type' => $message->getType(),
                    'channel' => self::TYPE,
                    'userId' => $userId !== '' ? $userId : null,
                    'teamId' => $teamId !== '' ? $teamId : null,
                    'projectId' => $message->getProjectId(),
                    'title' => $message->getTitle(),
                    'body' => $message->getBody(),
                ]);

                $this->database->createDocument('alerts', $document);
                $delivered++;
                $response->addResult($key);
            } catch (\Throwable $error) {
                $response->addResult($key, $error->getMessage());
            }
        }

        $response->setDeliveredTo($delivered);
        return $response->toArray();
    }

    /**
     * @return array<string>
     */
    private function buildPermissions(string $userId, string $teamId): array
    {
        $permissions = [];
        if ($userId !== '') {
            $permissions[] = Permission::read(Role::user($userId));
            $permissions[] = Permission::update(Role::user($userId));
            $permissions[] = Permission::delete(Role::user($userId));
        }
        if ($teamId !== '') {
            $permissions[] = Permission::read(Role::team($teamId));
            $permissions[] = Permission::update(Role::team($teamId, 'owner'));
            $permissions[] = Permission::delete(Role::team($teamId, 'owner'));
        }
        return $permissions;
    }
}
