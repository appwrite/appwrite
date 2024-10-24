<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Framework extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework ID.',
                'default' => '',
                'example' => 'sveltekit',
            ])
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Parent framework key.',
                'default' => '',
                'example' => 'sveltekit',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework Name.',
                'default' => '',
                'example' => 'SvelteKit'
            ])
            ->addRule('logo', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the logo image.',
                'default' => '',
                'example' => 'sveltekit.png',
            ])
            ->addRule('supports', [
                'type' => self::TYPE_STRING,
                'description' => 'List of supported architectures.',
                'default' => '',
                'example' => 'amd64',
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
        return 'Framework';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FRAMEWORK;
    }
}
