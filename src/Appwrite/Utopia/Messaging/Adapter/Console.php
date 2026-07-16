<?php

namespace Appwrite\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
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
        parent::__construct();
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
            $resourceType = $recipient['resourceType'];
            $resourceId = $recipient['resourceId'];
            $key = $resourceType . ':' . $resourceId;

            $messageId = $message->getMessageId();
            $recipientKey = self::buildRecipientKey($recipient);
            $recipientHash = $recipient['recipientHash'] ?? \substr(\md5($recipientKey), 0, 16);
            $documentId = $messageId !== null
                ? ($recipient['alertId'] ?? \substr($messageId, 0, 19) . '_' . $recipientHash)
                : ID::unique();

            try {
                $document = new Document([
                    '$id' => $documentId,
                    '$permissions' => $this->buildPermissions($resourceType, $resourceId, $message->getProjectId() ?? ''),
                    'messageId' => $messageId,
                    'recipientHash' => $recipientHash,
                    'type' => $message->getType(),
                    'channel' => self::TYPE,
                    'projectId' => $message->getProjectId(),
                    'projectInternalId' => $message->getProjectInternalId(),
                    'resourceType' => $resourceType,
                    'resourceId' => $resourceId,
                    'resourceInternalId' => $recipient['resourceInternalId'],
                    'parentResourceType' => $recipient['parentResourceType'],
                    'parentResourceId' => $recipient['parentResourceId'],
                    'parentResourceInternalId' => $recipient['parentResourceInternalId'],
                    'title' => $message->getTitle(),
                    'body' => $message->getBody(),
                ]);

                $this->database->createDocument('notifications', $document);
                $delivered++;
                $response->addResult($key);
            } catch (DuplicateException) {
                // Idempotent retry: row already exists for this messageId/recipient.
                // Treat as a successful (already-delivered) result so the worker
                // does not throw and re-queue.
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
    private function buildPermissions(string $resourceType, string $resourceId, string $projectId): array
    {
        $permissions = [];
        if ($resourceType === RESOURCE_TYPE_USERS) {
            $permissions[] = Permission::read(Role::user($resourceId));
            $permissions[] = Permission::update(Role::user($resourceId));
            $permissions[] = Permission::delete(Role::user($resourceId));
        }
        if ($resourceType === RESOURCE_TYPE_TEAMS) {
            $permissions[] = Permission::read(Role::team($resourceId));
            $permissions[] = Permission::update(Role::team($resourceId, 'owner'));
            $permissions[] = Permission::delete(Role::team($resourceId, 'owner'));
            if ($projectId !== '') {
                $permissions[] = Permission::read(Role::team($resourceId, 'project-' . $projectId . '-owner'));
                $permissions[] = Permission::update(Role::team($resourceId, 'project-' . $projectId . '-owner'));
                $permissions[] = Permission::delete(Role::team($resourceId, 'project-' . $projectId . '-owner'));
            }
        }
        return $permissions;
    }

    /**
     * @param array{address?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    private static function buildRecipientKey(array $recipient): string
    {
        return ($recipient['address'] ?? '')
            . ':' . $recipient['resourceType']
            . ':' . $recipient['resourceId']
            . ':' . $recipient['resourceInternalId']
            . ':' . $recipient['parentResourceType']
            . ':' . $recipient['parentResourceId']
            . ':' . $recipient['parentResourceInternalId'];
    }
}
