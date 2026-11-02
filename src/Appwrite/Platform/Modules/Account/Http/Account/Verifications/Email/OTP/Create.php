<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\OTP;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEmailVerificationOTP';
    }
}
