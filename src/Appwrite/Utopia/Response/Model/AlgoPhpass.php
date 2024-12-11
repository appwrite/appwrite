<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AlgoPhpass extends Model
{
    public function __construct()
    {
        // No options, because this can only be imported, and verifying doesnt require any configuration

        $this
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Algo type.',
                'default' => 'phpass',
                'example' => 'phpass',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AlgoPHPass';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ALGO_PHPASS;
    }
}
