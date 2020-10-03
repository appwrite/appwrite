<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class MembershipList extends BaseList
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('memberships', [
                'type' => Response::MODEL_MEMBERSHIP,
                'description' => 'List of memberships.',
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
        return 'Membership List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_MEMBERSHIP_LIST;
    }
}