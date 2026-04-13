<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Webhook extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Webhook creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Webhook update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook name.',
                'default' => '',
                'example' => 'My Webhook',
            ])
            ->addRule('url', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook URL endpoint.',
                'default' => '',
                'example' => 'https://example.com/webhook',
            ])
            ->addRule('events', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook trigger events.',
                'default' => [],
                'example' => [
                    'databases.tables.update',
                    'databases.collections.update'
                ],
                'array' => true,
            ])
            ->addRule('tls', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Indicates if SSL / TLS certificate verification is enabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('authUsername', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('authPassword', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication password.',
                'default' => '',
                'example' => 'password',
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Signature key which can be used to validate incoming webhook payloads.',
                'default' => '',
                'example' => 'ad3d581ca230e2b7059c545e5a',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Indicates if this webhook is enabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('logs', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook error logs from the most recent failure.',
                'default' => '',
                'example' => 'Failed to connect to remote server.',
            ])
            ->addRule('attempts', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of consecutive failed webhook attempts.',
                'default' => 0,
                'example' => 10,
            ]);
    }

    public function filter(Document $document): Document
    {
        $document->setAttribute('tls', $document->getAttribute('security'));
        $document->removeAttribute('security');

        $document->setAttribute('authUsername', $document->getAttribute('httpUser'));
        $document->removeAttribute('httpUser');

        $document->setAttribute('authPassword', $document->getAttribute('httpPass'));
        $document->removeAttribute('httpPass');

        $document->setAttribute('secret', $document->getAttribute('signatureKey'));
        $document->removeAttribute('signatureKey');

        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Webhook';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_WEBHOOK;
    }
}
