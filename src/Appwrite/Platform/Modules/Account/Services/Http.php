<?php

namespace Appwrite\Platform\Modules\Account\Services;

use Appwrite\Platform\Modules\Account\Http\Mfa\Authenticators\Create as CreateAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Mfa\Authenticators\Delete as DeleteAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Mfa\Authenticators\Update as UpdateAuthenticator;
use Appwrite\Platform\Modules\Account\Http\Mfa\Challenge\Create as CreateChallenge;
use Appwrite\Platform\Modules\Account\Http\Mfa\Challenge\Update as UpdateChallenge;
use Appwrite\Platform\Modules\Account\Http\Mfa\Factors\XList as ListFactors;
use Appwrite\Platform\Modules\Account\Http\Mfa\RecoveryCodes\Create as CreateRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Mfa\RecoveryCodes\Get as GetRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Mfa\RecoveryCodes\Update as UpdateRecoveryCodes;
use Appwrite\Platform\Modules\Account\Http\Mfa\Update as UpdateMfa;
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
