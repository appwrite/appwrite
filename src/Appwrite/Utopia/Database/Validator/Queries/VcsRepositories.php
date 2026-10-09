<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Query\VcsNamespace;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;

class VcsRepositories extends DepthFirst
{
    public function __construct()
    {
        parent::__construct([
            new Limit(),
            new Offset(),
            new VcsNamespace(),
        ]);
    }
}
