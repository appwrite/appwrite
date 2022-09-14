<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AlgoScrypt extends Model
{
    public function __construct()
    {
        $this
            ->addRule('costCpu', [
                'type' => self::TYPE_INTEGER,
                'description' => 'CPU complexity of computed hash.',
                'default' => '',
                'example' => 8,
            ])
            ->addRule('costMemory', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Memory complexity of computed hash.',
                'default' => '',
                'example' => 14,
            ])
            ->addRule('costParallel', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Parallelization of computed hash.',
                'default' => '',
                'example' => 1,
            ])
            ->addRule('length', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Length used to compute hash.',
                'default' => '',
                'example' => 1,
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
