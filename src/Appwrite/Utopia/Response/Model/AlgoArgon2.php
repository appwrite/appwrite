<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AlgoArgon2 extends Model
{
    public function __construct()
    {
        // No options if imported. If hashed by Appwrite, following configuration is available:
        $this
            ->addRule('memoryCost', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Memory used to compute hash.',
                'default' => '',
                'example' => 65536,
            ])
            ->addRule('timeCost', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Amount of time consumed to compute hash',
                'default' => '',
                'example' => 4,
            ])
            ->addRule('threads', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of threads used to compute hash.',
                'default' => '',
                'example' => 3,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AlgoArgon2';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ALGO_ARGON2;
    }
}
