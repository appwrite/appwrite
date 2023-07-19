<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class User extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'User creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'User update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'User name.',
                'default' => '',
                'example' => 'John Doe',
            ])
            ->addRule('password', [
                'type' => self::TYPE_STRING,
                'description' => 'Hashed user password.',
                'required' => false,
                'default' => '',
                'example' => '$argon2id$v=19$m=2048,t=4,p=3$aUZjLnliVWRINmFNTWMudg$5S+x+7uA31xFnrHFT47yFwcJeaP0w92L/4LdgrVRXxE',
            ])
            ->addRule('hash', [
                'type' => self::TYPE_STRING,
                'description' => 'Password hashing algorithm.',
                'required' => false,
                'default' => '',
                'example' => 'argon2',
            ])
            ->addRule('hashOptions', [
                'type' => [
                    Response::MODEL_ALGO_ARGON2,
                    Response::MODEL_ALGO_SCRYPT,
                    Response::MODEL_ALGO_SCRYPT_MODIFIED,
                    Response::MODEL_ALGO_BCRYPT,
                    Response::MODEL_ALGO_PHPASS,
                    Response::MODEL_ALGO_SHA,
                    Response::MODEL_ALGO_MD5, // keep least secure at the bottom. this order will be used in docs
                ],
                'description' => 'Password hashing algorithm configuration.',
                'required' => false,
                'default' => [],
                'example' => new \stdClass(),
                'array' => false,
            ])
            ->addRule('registration', [
                'type' => self::TYPE_DATETIME,
                'description' => 'User registration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('status', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'User status. Pass `true` for enabled and `false` for disabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('labels', [
                'type' => self::TYPE_STRING,
                'description' => 'Labels for the user.',
                'default' => [],
                'example' => ['vip'],
                'array' => true,
            ])
            ->addRule('passwordUpdate', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Password update time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('email', [
                'type' => self::TYPE_STRING,
                'description' => 'User email address.',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('phone', [
                'type' => self::TYPE_STRING,
                'description' => 'User phone number in E.164 format.',
                'default' => '',
                'example' => '+4930901820',
            ])
            ->addRule('emailVerification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Email verification status.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('phoneVerification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Phone verification status.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('prefs', [
                'type' => Response::MODEL_PREFERENCES,
                'description' => 'User preferences as a key-value object',
                'default' => new \stdClass(),
                'example' => ['theme' => 'pink', 'timezone' => 'UTC'],
            ])
        ;
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function filter(Document $document): Document
    {
        $prefs = $document->getAttribute('prefs');
        if ($prefs instanceof Document) {
            $prefs = $prefs->getArrayCopy();
        }

        if (is_array($prefs) && empty($prefs)) {
            $document->setAttribute('prefs', new \stdClass());
        }
        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'User';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USER;
    }
}
