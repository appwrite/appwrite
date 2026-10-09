<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Query\BranchCursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;

class Branches extends DepthFirst
{
    public function __construct()
    {
        parent::__construct([
            new Limit(),
            new Offset(),
            new BranchCursor(),
        ]);
    }
}
