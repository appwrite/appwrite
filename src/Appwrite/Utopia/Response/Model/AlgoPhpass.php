<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoPHPass')]
#[Type(Response::MODEL_ALGO_PHPASS)]
class AlgoPhpass extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: 'phpass',
        example: 'phpass'
    )]
    public string $type;
}
