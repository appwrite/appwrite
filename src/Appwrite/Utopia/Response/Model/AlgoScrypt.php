<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AlgoScrypt extends Model
{
    public function __construct()
    {
        $this
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Algo type.',
                'default' => 'scrypt',
                'example' => 'scrypt',
            ])
            ->addRule('costCpu', [
                'type' => self::TYPE_INTEGER,
                'description' => 'CPU complexity of computed hash.',
                'default' => 8,
                'example' => 8,
            ])
            ->addRule('costMemory', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Memory complexity of computed hash.',
                'default' => 14,
                'example' => 14,
            ])
            ->addRule('costParallel', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Parallelization of computed hash.',
                'default' => 1,
                'example' => 1,
            ])
            ->addRule('length', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Length used to compute hash.',
                'default' => 64,
                'example' => 64,
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
        return 'AlgoScrypt';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ALGO_SCRYPT;
    }
}
