<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ServiceStatus extends Model
{
    /**
     * @var bool
     */
    protected $public = false;
    
    public function __construct()
    {
        $this->addRule('service', [
                'type' => self::TYPE_STRING,
                'description' => 'Service name.',
                'default' => '',
                'example' => 'teams',
            ])
            ->addRule('status', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Status of the service',
                'default' => true,
                'example' => true,
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
        return 'ServiceStatus';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_SERVICE_STATUS;
    }
}