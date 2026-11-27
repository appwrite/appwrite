<?php

namespace Appwrite\Platform\Modules\Distribution\Services;

use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Create as CreateApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Delete as DeleteApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Get as GetApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Create as CreateTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Delete as DeleteTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Get as GetTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Update as UpdateTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\XList as ListTargets;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Update as UpdateApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\XList as ListVariables;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\XList as ListApps;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Apple\Create as CreateAppleConnection;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Apple\Update as UpdateAppleConnection;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Delete as DeleteConnection;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Get as GetConnection;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Google\Create as CreateGoogleConnection;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\Google\Update as UpdateGoogleConnection;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Connections\XList as ListConnections;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Android\Create as CreateAndroidSigningIdentity;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Delete as DeleteSigningIdentity;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Download\Get as GetSigningIdentityDownload;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Get as GetSigningIdentity;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Ios\Create as CreateIosSigningIdentity;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\Update as UpdateSigningIdentity;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities\XList as ListSigningIdentities;
use Appwrite\Platform\Modules\Distribution\Http\Init;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction('init', Init::class);

        $this->addAction(CreateApp::getName(), CreateApp::class);
        $this->addAction(ListApps::getName(), ListApps::class);
        $this->addAction(GetApp::getName(), GetApp::class);
        $this->addAction(UpdateApp::getName(), UpdateApp::class);
        $this->addAction(DeleteApp::getName(), DeleteApp::class);

        $this->addAction(CreateTarget::getName(), CreateTarget::class);
        $this->addAction(ListTargets::getName(), ListTargets::class);
        $this->addAction(GetTarget::getName(), GetTarget::class);
        $this->addAction(UpdateTarget::getName(), UpdateTarget::class);
        $this->addAction(DeleteTarget::getName(), DeleteTarget::class);

        $this->addAction(CreateVariable::getName(), CreateVariable::class);
        $this->addAction(ListVariables::getName(), ListVariables::class);
        $this->addAction(GetVariable::getName(), GetVariable::class);
        $this->addAction(UpdateVariable::getName(), UpdateVariable::class);
        $this->addAction(DeleteVariable::getName(), DeleteVariable::class);

        $this->addAction(CreateAppleConnection::getName(), CreateAppleConnection::class);
        $this->addAction(UpdateAppleConnection::getName(), UpdateAppleConnection::class);
        $this->addAction(CreateGoogleConnection::getName(), CreateGoogleConnection::class);
        $this->addAction(UpdateGoogleConnection::getName(), UpdateGoogleConnection::class);
        $this->addAction(ListConnections::getName(), ListConnections::class);
        $this->addAction(GetConnection::getName(), GetConnection::class);
        $this->addAction(DeleteConnection::getName(), DeleteConnection::class);

        $this->addAction(CreateIosSigningIdentity::getName(), CreateIosSigningIdentity::class);
        $this->addAction(CreateAndroidSigningIdentity::getName(), CreateAndroidSigningIdentity::class);
        $this->addAction(ListSigningIdentities::getName(), ListSigningIdentities::class);
        $this->addAction(GetSigningIdentity::getName(), GetSigningIdentity::class);
        $this->addAction(UpdateSigningIdentity::getName(), UpdateSigningIdentity::class);
        $this->addAction(DeleteSigningIdentity::getName(), DeleteSigningIdentity::class);
        $this->addAction(GetSigningIdentityDownload::getName(), GetSigningIdentityDownload::class);
    }
}
