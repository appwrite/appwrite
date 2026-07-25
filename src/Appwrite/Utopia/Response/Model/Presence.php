<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document as DatabaseDocument;

class Presence extends Model
{
    public function getName(): string
    {
        return 'Presence';
    }

    public function getType(): string
    {
        return Response::MODEL_PRESENCE;
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Presence ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Presence creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Presence update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Presence permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => ['read("any")'],
                'array' => true,
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '674af8f3e12a5f9ac0be',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Presence status.',
                'required' => false,
                'default' => null,
                'example' => 'online',
            ])
            ->addRule('source', [
                'type' => self::TYPE_STRING,
                'description' => 'Presence source.',
                'default' => '',
                'example' => 'HTTP',
            ])
            ->addRule('expiresAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Presence expiry date in ISO 8601 format.',
                'required' => false,
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('metadata', [
                'type' => self::TYPE_JSON,
                'description' => 'Presence metadata.',
                'required' => false,
                'default' => null,
                'example' => ['key' => 'value'],
            ]);
    }

    public function filter(DatabaseDocument $document): DatabaseDocument
    {
        $document->removeAttribute('$collection');
        $document->removeAttribute('$tenant');
        $document->removeAttribute('hostname');
        $document->removeAttribute('permissionsHash');
        $document->removeAttribute('userInternalId');

        foreach ($document->getAttributes() as $attribute) {
            if (\is_array($attribute)) {
                foreach ($attribute as $subAttribute) {
                    if ($subAttribute instanceof DatabaseDocument) {
                        $this->filter($subAttribute);
                    }
                }
            } elseif ($attribute instanceof DatabaseDocument) {
                $this->filter($attribute);
            }
        }

        return $document;
    }
}
