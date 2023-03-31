<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoArgon2')]
#[Type(Response::MODEL_ALGO_ARGON2)]
class AlgoArgon2 extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: '',
        example: 'argon2'
    )]
    public string $type;

    #[Rule(
        description: 'Memory used to compute hash.',
        default: '',
        example: 65536
    )]
    public int $memoryCost;

    #[Rule(
        description: 'Amount of time consumed to compute hash',
        default: '',
        example: 4
    )]
    public int $timeCost;

    #[Rule(
        description: 'Number of threads used to compute hash.',
        default: '',
        example: 3
    )]
    public int $threads;
}
