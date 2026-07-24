<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Folder extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Full folder path, with a trailing slash.',
                'default' => '',
                'example' => 'photos/2026/',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Folder name: the last segment of the path.',
                'default' => '',
                'example' => '2026',
            ])
            ->addRule('parent', [
                'type' => self::TYPE_STRING,
                'description' => 'Parent folder path with a trailing slash. Empty for top-level folders.',
                'default' => '',
                'example' => 'photos/',
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
        return 'Folder';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FOLDER;
    }
}
