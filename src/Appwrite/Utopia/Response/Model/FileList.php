<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class FileList extends BaseList
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('files', [
                'type' => Response::MODEL_FILE,
                'description' => 'List of files.',
                'example' => [],
                'array' => true,
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Files List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_FILE_LIST;
    }
}