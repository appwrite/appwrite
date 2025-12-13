<?php

namespace Appwrite\Platform\Modules\Account\Services;

use Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators\Create as CreateAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators\Delete as DeleteAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators\Update as UpdateAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Challenges\Create as CreateChallenge;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Challenges\Update as UpdateChallenge;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Factors\XList as ListFactors;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes\Create as CreateRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes\Get as GetRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes\Update as UpdateRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Account\MFA\Update as UpdateMfa;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(UpdateMfa::getName(), new UpdateMfa())
            ->addAction(ListFactors::getName(), new ListFactors())
            ->addAction(CreateAuthenticator::getName(), new CreateAuthenticator())
            ->addAction(UpdateAuthenticator::getName(), new UpdateAuthenticator())
            ->addAction(DeleteAuthenticator::getName(), new DeleteAuthenticator())
            ->addAction(CreateRecoveryCodes::getName(), new CreateRecoveryCodes())
            ->addAction(UpdateRecoveryCodes::getName(), new UpdateRecoveryCodes())
            ->addAction(GetRecoveryCodes::getName(), new GetRecoveryCodes())
            ->addAction(CreateChallenge::getName(), new CreateChallenge())
            ->addAction(UpdateChallenge::getName(), new UpdateChallenge());
    }
}
