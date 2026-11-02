<?php

namespace Appwrite\Platform\Modules\Account;

use Appwrite\Platform\Action;

abstract class Base extends Action
{
    protected const OAUTH_DEFAULT_SUCCESS = '/auth/oauth2/success';
    protected const OAUTH_DEFAULT_FAILURE = '/auth/oauth2/failure';
}
