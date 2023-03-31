<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoMD5')]
#[Type(Response::MODEL_ALGO_MD5)]
class AlgoMd5 extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: 'md5',
        example: 'md5'
    )]
    public string $type;
}
