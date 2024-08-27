<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VcsContent extends Model
{
    public function __construct()
    {
        $this
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Content size in bytes. Only files have size, and for directories, 0 is returned.',
                'default' => 0,
                'required' => false,
                'example' => 1523,
            ])
            ->addRule('isDirectory', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'If a content is a directory. Directories can be used to check nested contents.',
                'default' => false,
                'required' => false,
                'example' => true
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of directory or file.',
                'default' => "",
                'example' => 'Main.java',
                'array' => false,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'VcsContents';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VCS_CONTENT;
    }
}
