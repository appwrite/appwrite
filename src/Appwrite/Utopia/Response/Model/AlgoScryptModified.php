<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;

#[Name('AlgoScryptModified')]
#[Type(Response::MODEL_ALGO_SCRYPT_MODIFIED)]
class AlgoScryptModified extends Model
{
    #[Rule(
        description: 'Algo type.',
        default: 'scryptMod',
        example: 'scryptMod'
    )]
    public string $type;

    #[Rule(
        description: 'Salt used to compute hash.',
        default: '',
        example: 'UxLMreBr6tYyjQ=='
    )]
    public string $salt;

    #[Rule(
        description: 'Separator used to compute hash.',
        default: '',
        example: 'Bw=='
    )]
    public string $saltSeparator;

    #[Rule(
        description: 'Key used to compute hash.',
        default: '',
        example: 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ=='
    )]
    public string $signerKey;
}
