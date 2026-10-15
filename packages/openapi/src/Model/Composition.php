<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

enum Composition: string
{
    case ONE_OF = 'oneOf';
    case ANY_OF = 'anyOf';
    case ALL_OF = 'allOf';
}
