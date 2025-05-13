<?php

namespace Appwrite\Utopia\Response\Model;

use Ahc\Jwt\JWT;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;
use Utopia\System\System;

class ResourceToken extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Token ID.',
                'default' => '',
                'example' => 'bb8ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Token permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).',
                'default' => '',
                'example' => ['read("any")'],
                'array' => true,
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource ID.',
                'default' => '',
                'example' => '5e5ea5c168bb8:5e5ea5c168bb8',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource type.',
                'default' => '',
                'example' => TOKENS_RESOURCE_TYPE_FILES,
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'JWT encoded string.',
                'default' => '',
                // this is a secret but is converted to a JWT token when sent back to the client after filter.
                'example' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',
            ])
            ->addRule('accessedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Most recent access date in ISO 8601 format. This attribute is only updated again after ' . APP_RESOURCE_TOKEN_ACCESS / 60 / 60 . ' hours.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE
            ])
        ;
    }

    public function filter(Document $document): Document
    {
        $maxAge = PHP_INT_MAX;
        $expire = $document->getAttribute('expire');

        if ($expire !== null) {
            $now = new \DateTime();
            $expiryDate = new \DateTime($expire);

            // set 1 min if expired, we check for expiry later on route hooks for validation!
            $maxAge = min(360, $expiryDate->getTimestamp() - $now->getTimestamp());
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $maxAge, 10);
        $secret = $jwt->encode([
            'tokenId' => $document->getId(),
            'resourceId' => $document->getAttribute('resourceId'),
            'resourceType' => $document->getAttribute('resourceType'),
            'resourceInternalId' => $document->getAttribute('resourceInternalId'),
        ]);

        $document->setAttribute('secret', $secret);

        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ResourceToken';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_RESOURCE_TOKEN;
    }
}
