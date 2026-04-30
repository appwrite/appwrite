<?php

namespace Appwrite\Platform\Modules\Project\Services;

use Appwrite\Platform\Modules\Project\Http\Init;
use Appwrite\Platform\Modules\Project\Http\Project\AuthMethods\Update as UpdateAuthMethod;
use Appwrite\Platform\Modules\Project\Http\Project\Delete as DeleteProject;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Create as CreateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Delete as DeleteKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Ephemeral\Create as CreateEphemeralKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Get as GetKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Update as UpdateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\XList as ListKeys;
use Appwrite\Platform\Modules\Project\Http\Project\Labels\Update as UpdateProjectLabels;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Create as CreateMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Delete as DeleteMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Get as GetMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Update as UpdateMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\XList as ListMockPhones;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Amazon\Update as UpdateOAuth2Amazon;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Apple\Update as UpdateOAuth2Apple;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Auth0\Update as UpdateOAuth2Auth0;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Authentik\Update as UpdateOAuth2Authentik;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Autodesk\Update as UpdateOAuth2Autodesk;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Bitbucket\Update as UpdateOAuth2Bitbucket;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Bitly\Update as UpdateOAuth2Bitly;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Box\Update as UpdateOAuth2Box;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dailymotion\Update as UpdateOAuth2Dailymotion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord\Update as UpdateOAuth2Discord;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Disqus\Update as UpdateOAuth2Disqus;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dropbox\Update as UpdateOAuth2Dropbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Etsy\Update as UpdateOAuth2Etsy;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Facebook\Update as UpdateOAuth2Facebook;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Figma\Update as UpdateOAuth2Figma;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\FusionAuth\Update as UpdateOAuth2FusionAuth;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Get as GetOAuth2Provider;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\GitHub\Update as UpdateOAuth2GitHub;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Gitlab\Update as UpdateOAuth2Gitlab;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Google\Update as UpdateOAuth2Google;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Keycloak\Update as UpdateOAuth2Keycloak;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Kick\Update as UpdateOAuth2Kick;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Linkedin\Update as UpdateOAuth2Linkedin;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Microsoft\Update as UpdateOAuth2Microsoft;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Notion\Update as UpdateOAuth2Notion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Oidc\Update as UpdateOAuth2Oidc;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Okta\Update as UpdateOAuth2Okta;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Paypal\Update as UpdateOAuth2Paypal;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\PaypalSandbox\Update as UpdateOAuth2PaypalSandbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Podio\Update as UpdateOAuth2Podio;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Salesforce\Update as UpdateOAuth2Salesforce;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Slack\Update as UpdateOAuth2Slack;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Spotify\Update as UpdateOAuth2Spotify;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Stripe\Update as UpdateOAuth2Stripe;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Tradeshift\Update as UpdateOAuth2Tradeshift;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TradeshiftSandbox\Update as UpdateOAuth2TradeshiftSandbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Twitch\Update as UpdateOAuth2Twitch;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\WordPress\Update as UpdateOAuth2WordPress;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\X\Update as UpdateOAuth2X;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\XList as ListOAuth2Providers;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Yahoo\Update as UpdateOAuth2Yahoo;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Yandex\Update as UpdateOAuth2Yandex;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoho\Update as UpdateOAuth2Zoho;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoom\Update as UpdateOAuth2Zoom;
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
use Appwrite\Platform\Modules\Project\Http\Project\Policies\Get as GetPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\MembershipPrivacy\Update as UpdateMembershipPrivacyPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordDictionary\Update as UpdatePasswordDictionaryPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordHistory\Update as UpdatePasswordHistoryPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPersonalData\Update as UpdatePasswordPersonalDataPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionAlert\Update as UpdateSessionAlertPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionDuration\Update as UpdateSessionDurationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionInvalidation\Update as UpdateSessionInvalidationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionLimit\Update as UpdateSessionLimitPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\UserLimit\Update as UpdateUserLimitPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\XList as ListPolicies;
use Appwrite\Platform\Modules\Project\Http\Project\Protocols\Update as UpdateProjectProtocol;
use Appwrite\Platform\Modules\Project\Http\Project\Services\Update as UpdateProjectService;
use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Tests\Create as CreateSMTPTest;
use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Update as UpdateSMTP;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\Get as GetTemplate;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\Update as UpdateTemplate;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\XList as ListTemplates;
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
        $this->addAction(DeleteProject::getName(), new DeleteProject());
        $this->addAction(UpdateProjectLabels::getName(), new UpdateProjectLabels());
        $this->addAction(UpdateProjectProtocol::getName(), new UpdateProjectProtocol());
        $this->addAction(UpdateProjectService::getName(), new UpdateProjectService());

        // SMTP
        $this->addAction(UpdateSMTP::getName(), new UpdateSMTP());
        $this->addAction(CreateSMTPTest::getName(), new CreateSMTPTest());

        // Templates
        $this->addAction(ListTemplates::getName(), new ListTemplates());
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
        $this->addAction(CreateEphemeralKey::getName(), new CreateEphemeralKey());
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

        // Mock Phones
        $this->addAction(CreateMockPhone::getName(), new CreateMockPhone());
        $this->addAction(ListMockPhones::getName(), new ListMockPhones());
        $this->addAction(GetMockPhone::getName(), new GetMockPhone());
        $this->addAction(UpdateMockPhone::getName(), new UpdateMockPhone());
        $this->addAction(DeleteMockPhone::getName(), new DeleteMockPhone());

        // Policies
        $this->addAction(ListPolicies::getName(), new ListPolicies());
        $this->addAction(GetPolicy::getName(), new GetPolicy());
        $this->addAction(UpdateMembershipPrivacyPolicy::getName(), new UpdateMembershipPrivacyPolicy());
        $this->addAction(UpdatePasswordDictionaryPolicy::getName(), new UpdatePasswordDictionaryPolicy());
        $this->addAction(UpdatePasswordHistoryPolicy::getName(), new UpdatePasswordHistoryPolicy());
        $this->addAction(UpdatePasswordPersonalDataPolicy::getName(), new UpdatePasswordPersonalDataPolicy());
        $this->addAction(UpdateSessionAlertPolicy::getName(), new UpdateSessionAlertPolicy());
        $this->addAction(UpdateSessionDurationPolicy::getName(), new UpdateSessionDurationPolicy());
        $this->addAction(UpdateSessionInvalidationPolicy::getName(), new UpdateSessionInvalidationPolicy());
        $this->addAction(UpdateSessionLimitPolicy::getName(), new UpdateSessionLimitPolicy());
        $this->addAction(UpdateUserLimitPolicy::getName(), new UpdateUserLimitPolicy());

        // Auth Methods
        $this->addAction(UpdateAuthMethod::getName(), new UpdateAuthMethod());

        // OAuth2
        $this->addAction(ListOAuth2Providers::getName(), new ListOAuth2Providers());
        $this->addAction(GetOAuth2Provider::getName(), new GetOAuth2Provider());
        $this->addAction(UpdateOAuth2GitHub::getName(), new UpdateOAuth2GitHub());
        $this->addAction(UpdateOAuth2Discord::getName(), new UpdateOAuth2Discord());
        $this->addAction(UpdateOAuth2Figma::getName(), new UpdateOAuth2Figma());
        $this->addAction(UpdateOAuth2Dropbox::getName(), new UpdateOAuth2Dropbox());
        $this->addAction(UpdateOAuth2Dailymotion::getName(), new UpdateOAuth2Dailymotion());
        $this->addAction(UpdateOAuth2Bitbucket::getName(), new UpdateOAuth2Bitbucket());
        $this->addAction(UpdateOAuth2Bitly::getName(), new UpdateOAuth2Bitly());
        $this->addAction(UpdateOAuth2Box::getName(), new UpdateOAuth2Box());
        $this->addAction(UpdateOAuth2Autodesk::getName(), new UpdateOAuth2Autodesk());
        $this->addAction(UpdateOAuth2Google::getName(), new UpdateOAuth2Google());
        $this->addAction(UpdateOAuth2Zoom::getName(), new UpdateOAuth2Zoom());
        $this->addAction(UpdateOAuth2Zoho::getName(), new UpdateOAuth2Zoho());
        $this->addAction(UpdateOAuth2Yandex::getName(), new UpdateOAuth2Yandex());
        $this->addAction(UpdateOAuth2X::getName(), new UpdateOAuth2X());
        $this->addAction(UpdateOAuth2WordPress::getName(), new UpdateOAuth2WordPress());
        $this->addAction(UpdateOAuth2Twitch::getName(), new UpdateOAuth2Twitch());
        $this->addAction(UpdateOAuth2Stripe::getName(), new UpdateOAuth2Stripe());
        $this->addAction(UpdateOAuth2Spotify::getName(), new UpdateOAuth2Spotify());
        $this->addAction(UpdateOAuth2Slack::getName(), new UpdateOAuth2Slack());
        $this->addAction(UpdateOAuth2Podio::getName(), new UpdateOAuth2Podio());
        $this->addAction(UpdateOAuth2Notion::getName(), new UpdateOAuth2Notion());
        $this->addAction(UpdateOAuth2Salesforce::getName(), new UpdateOAuth2Salesforce());
        $this->addAction(UpdateOAuth2Yahoo::getName(), new UpdateOAuth2Yahoo());
        $this->addAction(UpdateOAuth2Linkedin::getName(), new UpdateOAuth2Linkedin());
        $this->addAction(UpdateOAuth2Disqus::getName(), new UpdateOAuth2Disqus());
        $this->addAction(UpdateOAuth2Amazon::getName(), new UpdateOAuth2Amazon());
        $this->addAction(UpdateOAuth2Etsy::getName(), new UpdateOAuth2Etsy());
        $this->addAction(UpdateOAuth2Facebook::getName(), new UpdateOAuth2Facebook());
        $this->addAction(UpdateOAuth2Tradeshift::getName(), new UpdateOAuth2Tradeshift());
        $this->addAction(UpdateOAuth2TradeshiftSandbox::getName(), new UpdateOAuth2TradeshiftSandbox());
        $this->addAction(UpdateOAuth2Paypal::getName(), new UpdateOAuth2Paypal());
        $this->addAction(UpdateOAuth2PaypalSandbox::getName(), new UpdateOAuth2PaypalSandbox());
        $this->addAction(UpdateOAuth2Gitlab::getName(), new UpdateOAuth2Gitlab());
        $this->addAction(UpdateOAuth2Authentik::getName(), new UpdateOAuth2Authentik());
        $this->addAction(UpdateOAuth2Auth0::getName(), new UpdateOAuth2Auth0());
        $this->addAction(UpdateOAuth2FusionAuth::getName(), new UpdateOAuth2FusionAuth());
        $this->addAction(UpdateOAuth2Keycloak::getName(), new UpdateOAuth2Keycloak());
        $this->addAction(UpdateOAuth2Oidc::getName(), new UpdateOAuth2Oidc());
        $this->addAction(UpdateOAuth2Okta::getName(), new UpdateOAuth2Okta());
        $this->addAction(UpdateOAuth2Kick::getName(), new UpdateOAuth2Kick());
        $this->addAction(UpdateOAuth2Apple::getName(), new UpdateOAuth2Apple());
        $this->addAction(UpdateOAuth2Microsoft::getName(), new UpdateOAuth2Microsoft());
    }
}
