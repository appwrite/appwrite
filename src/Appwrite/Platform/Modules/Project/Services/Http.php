<?php

namespace Appwrite\Platform\Modules\Project\Services;

use Appwrite\Platform\Modules\Project\Http\Init;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Create as CreateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Delete as DeleteKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Get as GetKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Update as UpdateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\XList as ListKeys;
use Appwrite\Platform\Modules\Project\Http\Project\Labels\Update as UpdateProjectLabels;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android\Create as CreateAndroidPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android\Update as UpdateAndroidPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple\Create as CreateApplePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple\Update as UpdateApplePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Delete as DeletePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Get as GetPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux\Create as CreateLinuxPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux\Update as UpdateLinuxPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Create as CreateWebPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Update as UpdateWebPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows\Create as CreateWindowsPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows\Update as UpdateWindowsPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\XList as ListPlatforms;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\MembershipPrivacy\Update as UpdateMembershipPrivacyPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordDictionary\Update as UpdatePasswordDictionaryPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordHistory\Update as UpdatePasswordHistoryPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPersonalData\Update as UpdatePasswordPersonalDataPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionAlert\Update as UpdateSessionAlertPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionDuration\Update as UpdateSessionDurationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionInvalidation\Update as UpdateSessionInvalidationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionLimit\Update as UpdateSessionLimitPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\UserLimit\Update as UpdateUserLimitPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Protocols\Update as UpdateProjectProtocol;
use Appwrite\Platform\Modules\Project\Http\Project\Services\Update as UpdateProjectService;
use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Tests\Create as CreateSMTPTest;
use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Update as UpdateSMTP;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\Get as GetTemplate;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\Update as UpdateTemplate;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\XList as ListVariables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Hooks
        $this->addAction(Init::getName(), new Init());

        // Project
        $this->addAction(UpdateProjectLabels::getName(), new UpdateProjectLabels());
        $this->addAction(UpdateProjectProtocol::getName(), new UpdateProjectProtocol());
        $this->addAction(UpdateProjectService::getName(), new UpdateProjectService());

        // SMTP
        $this->addAction(UpdateSMTP::getName(), new UpdateSMTP());
        $this->addAction(CreateSMTPTest::getName(), new CreateSMTPTest());

        // Templates
        $this->addAction(GetTemplate::getName(), new GetTemplate());
        $this->addAction(UpdateTemplate::getName(), new UpdateTemplate());

        // Variables
        $this->addAction(CreateVariable::getName(), new CreateVariable());
        $this->addAction(ListVariables::getName(), new ListVariables());
        $this->addAction(GetVariable::getName(), new GetVariable());
        $this->addAction(DeleteVariable::getName(), new DeleteVariable());
        $this->addAction(UpdateVariable::getName(), new UpdateVariable());

        // Keys
        $this->addAction(CreateKey::getName(), new CreateKey());
        $this->addAction(ListKeys::getName(), new ListKeys());
        $this->addAction(GetKey::getName(), new GetKey());
        $this->addAction(DeleteKey::getName(), new DeleteKey());
        $this->addAction(UpdateKey::getName(), new UpdateKey());

        // Platforms
        $this->addAction(DeletePlatform::getName(), new DeletePlatform());
        $this->addAction(UpdateWebPlatform::getName(), new UpdateWebPlatform());
        $this->addAction(UpdateApplePlatform::getName(), new UpdateApplePlatform());
        $this->addAction(UpdateAndroidPlatform::getName(), new UpdateAndroidPlatform());
        $this->addAction(UpdateWindowsPlatform::getName(), new UpdateWindowsPlatform());
        $this->addAction(UpdateLinuxPlatform::getName(), new UpdateLinuxPlatform());
        $this->addAction(CreateWebPlatform::getName(), new CreateWebPlatform());
        $this->addAction(CreateApplePlatform::getName(), new CreateApplePlatform());
        $this->addAction(CreateAndroidPlatform::getName(), new CreateAndroidPlatform());
        $this->addAction(CreateWindowsPlatform::getName(), new CreateWindowsPlatform());
        $this->addAction(CreateLinuxPlatform::getName(), new CreateLinuxPlatform());
        $this->addAction(GetPlatform::getName(), new GetPlatform());
        $this->addAction(ListPlatforms::getName(), new ListPlatforms());

        // Policies
        $this->addAction(UpdateMembershipPrivacyPolicy::getName(), new UpdateMembershipPrivacyPolicy());
        $this->addAction(UpdatePasswordDictionaryPolicy::getName(), new UpdatePasswordDictionaryPolicy());
        $this->addAction(UpdatePasswordHistoryPolicy::getName(), new UpdatePasswordHistoryPolicy());
        $this->addAction(UpdatePasswordPersonalDataPolicy::getName(), new UpdatePasswordPersonalDataPolicy());
        $this->addAction(UpdateSessionAlertPolicy::getName(), new UpdateSessionAlertPolicy());
        $this->addAction(UpdateSessionDurationPolicy::getName(), new UpdateSessionDurationPolicy());
        $this->addAction(UpdateSessionInvalidationPolicy::getName(), new UpdateSessionInvalidationPolicy());
        $this->addAction(UpdateSessionLimitPolicy::getName(), new UpdateSessionLimitPolicy());
        $this->addAction(UpdateUserLimitPolicy::getName(), new UpdateUserLimitPolicy());
    }
}
