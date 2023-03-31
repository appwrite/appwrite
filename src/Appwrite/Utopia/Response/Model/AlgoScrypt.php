<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoScrypt')]
#[Type(Response::MODEL_ALGO_SCRYPT)]
class AlgoScrypt extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: 'scrypt',
        example: 'scrypt'
    )]
    public string $type;

    #[Rule(
        description: 'CPU complexity of computed hash.',
        default: 8,
        example: 8
    )]
    public int $costCpu;

    #[Rule(
        description: 'Memory complexity of computed hash.',
        default: 14,
        example: 14
    )]
    public int $costMemory;

    #[Rule(
        description: 'Parallelization of computed hash.',
        default: 1,
        example: 1
    )]
    public int $costParallel;

    #[Rule(
        description: 'Length used to compute hash.',
        default: 64,
        example: 64
    )]
    public int $length;
}
