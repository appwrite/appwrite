<?php

namespace Appwrite\Platform\Modules\Mock\Services;

use Appwrite\Platform\Modules\Mock\Http\Mock\APIKey\Unprefixed\Create as CreateAPIKeyUnprefixed;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Apps\XList as ListDistributionAppleApps;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds\XList as ListDistributionAppleBuilds;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Apps\Create as CreateDistributionAppleSeedApp;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Builds\Create as CreateDistributionAppleSeedBuild;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Keys\Create as CreateDistributionAppleSeedKey;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Bundles\Create as CreateDistributionGoogleBundleUpload;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Bundles\Update as UpdateDistributionGoogleBundleUpload;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Create as CreateDistributionGoogleEdit;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Delete as DeleteDistributionGoogleEdit;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Get as GetDistributionGoogleEdit;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Tracks\Get as GetDistributionGoogleTrack;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Tracks\Update as UpdateDistributionGoogleTrack;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Tracks\XList as ListDistributionGoogleTracks;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Update as UpdateDistributionGoogleEdit;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Tracks\Releases\XList as ListDistributionGoogleReleases;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Applications\Create as CreateDistributionGoogleSeedApplication;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Bundles\Create as CreateDistributionGoogleSeedBundle;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Reviews\Create as CreateDistributionGoogleSeedReview;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Rollouts\Create as CreateDistributionGoogleSeedRollout;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\ServiceAccounts\Create as CreateDistributionGoogleSeedServiceAccount;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Stalls\Create as CreateDistributionGoogleSeedStall;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Uploads\XList as ListDistributionGoogleSeedUploads;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Token\Create as CreateDistributionGoogleToken;
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

        $this->addAction('getOAuth2', GetOAuth2::class);
        $this->addAction('getLocale', GetLocale::class);
        $this->addAction('getOAuth2Token', GetOAuth2Token::class);
        $this->addAction('getOAuth2Photo', GetOAuth2Photo::class);
        $this->addAction('getFaviconPage', GetFaviconPage::class);
        $this->addAction('getBadIcon', GetBadIcon::class);
        $this->addAction('getFaviconPageTruncated', GetFaviconPageTruncated::class);
        $this->addAction('getTruncatedIcon', GetTruncatedIcon::class);
        $this->addAction('getOAuth2PhotoRefreshed', GetOAuth2PhotoRefreshed::class);
        $this->addAction('getOAuth2User', GetOAuth2User::class);
        $this->addAction('getOAuth2UserUnverified', GetOAuth2UserUnverified::class);
        $this->addAction('getOAuth2UserNoEmail', GetOAuth2UserNoEmail::class);
        $this->addAction('updateOAuth2Native', UpdateOAuth2Native::class);
        $this->addAction('getOAuth2JWKS', GetOAuth2JWKS::class);
        $this->addAction('getOAuth2IdToken', GetOAuth2IdToken::class);
        $this->addAction('getOAuth2Success', GetOAuth2Success::class);
        $this->addAction('getOAuth2Failure', GetOAuth2Failure::class);
        $this->addAction('createAPIKeyUnprefixed', CreateAPIKeyUnprefixed::class);
        $this->addAction('getGitHubCallback', GetGitHubCallback::class);
        $this->addAction('createDistributionGoogleToken', CreateDistributionGoogleToken::class);
        $this->addAction('createDistributionGoogleSeedServiceAccount', CreateDistributionGoogleSeedServiceAccount::class);
        $this->addAction('createDistributionGoogleSeedApplication', CreateDistributionGoogleSeedApplication::class);
        $this->addAction('createDistributionGoogleEdit', CreateDistributionGoogleEdit::class);
        $this->addAction('getDistributionGoogleEdit', GetDistributionGoogleEdit::class);
        $this->addAction('updateDistributionGoogleEdit', UpdateDistributionGoogleEdit::class);
        $this->addAction('deleteDistributionGoogleEdit', DeleteDistributionGoogleEdit::class);
        $this->addAction('listDistributionGoogleTracks', ListDistributionGoogleTracks::class);
        $this->addAction('createDistributionAppleSeedKey', CreateDistributionAppleSeedKey::class);
        $this->addAction('createDistributionAppleSeedApp', CreateDistributionAppleSeedApp::class);
        $this->addAction('listDistributionAppleApps', ListDistributionAppleApps::class);
        $this->addAction('createDistributionAppleSeedBuild', CreateDistributionAppleSeedBuild::class);
        $this->addAction('listDistributionAppleBuilds', ListDistributionAppleBuilds::class);
        $this->addAction('createDistributionGoogleBundleUpload', CreateDistributionGoogleBundleUpload::class);
        $this->addAction('updateDistributionGoogleBundleUpload', UpdateDistributionGoogleBundleUpload::class);
        $this->addAction('getDistributionGoogleTrack', GetDistributionGoogleTrack::class);
        $this->addAction('updateDistributionGoogleTrack', UpdateDistributionGoogleTrack::class);
        $this->addAction('listDistributionGoogleReleases', ListDistributionGoogleReleases::class);
        $this->addAction('createDistributionGoogleSeedReview', CreateDistributionGoogleSeedReview::class);
        $this->addAction('createDistributionGoogleSeedBundle', CreateDistributionGoogleSeedBundle::class);
        $this->addAction('createDistributionGoogleSeedStall', CreateDistributionGoogleSeedStall::class);
        $this->addAction('listDistributionGoogleSeedUploads', ListDistributionGoogleSeedUploads::class);
        $this->addAction('createDistributionGoogleSeedRollout', CreateDistributionGoogleSeedRollout::class);
        $this->addAction('shutdown', Shutdown::class);
    }
}
