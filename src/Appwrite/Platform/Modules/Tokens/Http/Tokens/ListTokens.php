<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class ListTokens extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'ListTokens';
    }
}