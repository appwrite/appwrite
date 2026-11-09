<?php

namespace Appwrite\Platform\Modules\Avatars\Services;

use Appwrite\Platform\Modules\Avatars\Http\Browsers\Get as GetBrowser;
use Appwrite\Platform\Modules\Avatars\Http\CreditCards\Get as GetCreditCard;
use Appwrite\Platform\Modules\Avatars\Http\Favicon\Get as GetFavicon;
use Appwrite\Platform\Modules\Avatars\Http\Flags\Get as GetFlag;
use Appwrite\Platform\Modules\Avatars\Http\Image\Get as GetImage;
use Appwrite\Platform\Modules\Avatars\Http\Initials\Get as GetInitials;
use Appwrite\Platform\Modules\Avatars\Http\Photo\Delete as DeletePhoto;
use Appwrite\Platform\Modules\Avatars\Http\Photo\Get as GetPhoto;
use Appwrite\Platform\Modules\Avatars\Http\Photo\Update as UpdatePhoto;
use Appwrite\Platform\Modules\Avatars\Http\QR\Get as GetQR;
use Appwrite\Platform\Modules\Avatars\Http\Screenshots\Get as GetScreenshot;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction('getCreditCard', GetCreditCard::class);
        $this->addAction('getBrowser', GetBrowser::class);
        $this->addAction('getFlag', GetFlag::class);
        $this->addAction('getImage', GetImage::class);
        $this->addAction('getFavicon', GetFavicon::class);
        $this->addAction('getQR', GetQR::class);
        $this->addAction('getInitials', GetInitials::class);
        $this->addAction('getScreenshot', GetScreenshot::class);
        $this->addAction('getPhoto', GetPhoto::class);
        $this->addAction('updatePhoto', UpdatePhoto::class);
        $this->addAction('deletePhoto', DeletePhoto::class);
    }
}
