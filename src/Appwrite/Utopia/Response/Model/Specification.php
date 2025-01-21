<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Specification extends Model
{
    public function __construct()
    {

        $this->addRule('memory', [
            'type' => self::TYPE_INTEGER,
            'description' => 'Memory size in MB.',
            'default' => 0,
            'example' => 512
        ]);
        $this->addRule('cpus', [
            'type' => self::TYPE_FLOAT,
            'description' => 'Number of CPUs.',
            'default' => 0,
            'example' => 1
        ]);
        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Is size enabled.',
            'default' => false,
            'example' => true
        ]);
        $this->addRule('slug', [
            'type' => self::TYPE_STRING,
            'description' => 'Size slug.',
            'default' => '',
            'example' => APP_COMPUTE_SPECIFICATION_DEFAULT
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Specification';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SPECIFICATION;
    }
}
