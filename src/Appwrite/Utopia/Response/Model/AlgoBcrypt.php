<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoBcrypt')]
#[Type(Response::MODEL_ALGO_BCRYPT)];
class AlgoBcrypt extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: 'bcrypt',
        example: 'bcrypt'
    )]
    public string $type;
}
