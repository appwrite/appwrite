<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoSHA')]
#[Type(Response::MODEL_ALGO_SHA)]
class AlgoSha extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: 'sha',
        example: 'sha'
    )]
    public string $type;
}
