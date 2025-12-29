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

        // Use a large but reasonable maxAge to avoid auto-exp when we set explicit exp
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), RESOURCE_TOKEN_ALGORITHM, RESOURCE_TOKEN_MAX_AGE, RESOURCE_TOKEN_LEEWAY); // 10 years

        $payload = [
            'tokenId' => $document->getId(),
            'resourceId' => $document->getAttribute('resourceId'),
            'resourceType' => $document->getAttribute('resourceType'),
            'resourceInternalId' => $document->getAttribute('resourceInternalId'),
        ];

        $createdDate = new \DateTime($document->getCreatedAt());
        $payload['iat'] = $createdDate->getTimestamp();

        // Set explicit expiration in JWT payload if we have an expiry date
        if ($expire !== null) {
            $expiryDate = new \DateTime($expire);
            $payload['exp'] = $expiryDate->getTimestamp();
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
