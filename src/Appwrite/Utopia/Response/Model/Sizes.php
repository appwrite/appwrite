<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Sizes extends Model
{
    public function __construct()
    {
        $this
            ->addRule('sizes', [
                'type' => self::TYPE_STRING,
                'description' => 'Different types of runtime machine sizes available.',
                'default' => '',
                'example' => ['s-1vcpu-512mb', 's-1vcpu-1gb', 's-2vcpu-2gb'],
                'array' => true,
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
        return 'Sizes';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SIZES;
    }
}
