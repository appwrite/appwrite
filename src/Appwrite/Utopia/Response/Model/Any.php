<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Options;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;

#[Name('Any')]
#[Type(Response::MODEL_ANY)]
#[Options(
    any: true
)]
class Any extends Model
{
}
