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
        $expire = $document->getAttribute('expire');
        $now = new \DateTime();

        // Calculate expiration timestamp for JWT
        $expTimestamp = null;
        if ($expire !== null) {
            $expiryDate = new \DateTime($expire);
            $secondsUntilExpiry = $expiryDate->getTimestamp() - $now->getTimestamp();

            // If token is expired, set expiration to 1 minute from now
            // We check for actual expiry later on route hooks for validation
            if ($secondsUntilExpiry <= 0) {
                $expTimestamp = $now->getTimestamp() + 60;
            } else {
                $expTimestamp = $expiryDate->getTimestamp();
            }
        }

        // Use maxAge as fallback, but rely on exp in payload for actual expiration
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', PHP_INT_MAX, 10);

        $payload = [
            'tokenId' => $document->getId(),
            'resourceId' => $document->getAttribute('resourceId'),
            'resourceType' => $document->getAttribute('resourceType'),
            'resourceInternalId' => $document->getAttribute('resourceInternalId'),
        ];

        // Set explicit expiration in JWT payload if we have an expiry date
        if ($expTimestamp !== null) {
            $payload['exp'] = $expTimestamp;
        }

        $secret = $jwt->encode($payload);

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
