<?php

namespace Appwrite\Platform\Modules\Mock\Services;

use Appwrite\Platform\Modules\Mock\Http\Mock\APIKey\Unprefixed\Create as CreateAPIKeyUnprefixed;
use Appwrite\Platform\Modules\Mock\Http\Mock\GitHub\Callback\Get as GetGitHubCallback;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\BadIcon\Get as GetBadIcon;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\FaviconPage\Get as GetFaviconPage;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\FaviconPage\Truncated\Get as GetFaviconPageTruncated;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\TruncatedIcon\Get as GetTruncatedIcon;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Failure\Get as GetOAuth2Failure;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Get as GetOAuth2;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\IdToken\Get as GetOAuth2IdToken;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\JWKS\Get as GetOAuth2JWKS;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Native\Update as UpdateOAuth2Native;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Photo\Get as GetOAuth2Photo;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Photo\Refreshed\Get as GetOAuth2PhotoRefreshed;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Success\Get as GetOAuth2Success;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Token\Get as GetOAuth2Token;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\Get as GetOAuth2User;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\NoEmail\Get as GetOAuth2UserNoEmail;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\Unverified\Get as GetOAuth2UserUnverified;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Locale\Get as GetLocale;
use Appwrite\Platform\Modules\Mock\Http\Shutdown;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetOAuth2::getName(), new GetOAuth2());
        $this->addAction(GetLocale::getName(), new GetLocale());
        $this->addAction(GetOAuth2Token::getName(), new GetOAuth2Token());
        $this->addAction(GetOAuth2Photo::getName(), new GetOAuth2Photo());
        $this->addAction(GetFaviconPage::getName(), new GetFaviconPage());
        $this->addAction(GetBadIcon::getName(), new GetBadIcon());
        $this->addAction(GetFaviconPageTruncated::getName(), new GetFaviconPageTruncated());
        $this->addAction(GetTruncatedIcon::getName(), new GetTruncatedIcon());
        $this->addAction(GetOAuth2PhotoRefreshed::getName(), new GetOAuth2PhotoRefreshed());
        $this->addAction(GetOAuth2User::getName(), new GetOAuth2User());
        $this->addAction(GetOAuth2UserUnverified::getName(), new GetOAuth2UserUnverified());
        $this->addAction(GetOAuth2UserNoEmail::getName(), new GetOAuth2UserNoEmail());
        $this->addAction(UpdateOAuth2Native::getName(), new UpdateOAuth2Native());
        $this->addAction(GetOAuth2JWKS::getName(), new GetOAuth2JWKS());
        $this->addAction(GetOAuth2IdToken::getName(), new GetOAuth2IdToken());
        $this->addAction(GetOAuth2Success::getName(), new GetOAuth2Success());
        $this->addAction(GetOAuth2Failure::getName(), new GetOAuth2Failure());
        $this->addAction(CreateAPIKeyUnprefixed::getName(), new CreateAPIKeyUnprefixed());
        $this->addAction(GetGitHubCallback::getName(), new GetGitHubCallback());
        $this->addAction(Shutdown::getName(), new Shutdown());
    }
}
