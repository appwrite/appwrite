<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Passkey extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Passkey ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Passkey creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Passkey update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Passkey name.',
                'default' => '',
                'example' => 'My laptop',
            ])
            ->addRule('accessedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Most recent sign-in with this passkey in ISO 8601 format. Empty until the passkey is used to sign in.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('backedUp', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the passkey is synced to a cloud account, such as iCloud Keychain or Google Password Manager, rather than bound to one device.',
                'default' => false,
                'example' => true,
            ])
        ;
    }

    public function filter(Document $document): Document
    {
        $data = $document->getAttribute('data', []);

        return $document
            ->setAttribute('name', $data['name'] ?? '')
            ->setAttribute('accessedAt', $data['accessedAt'] ?? '')
            ->setAttribute('backedUp', $data['record']['backupStatus'] ?? false);
    }

    public function getName(): string
    {
        return 'Passkey';
    }

    public function getType(): string
    {
        return Response::MODEL_PASSKEY;
    }
}
