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

        $this->addAction(GetCreditCard::getName(), GetCreditCard::class);
        $this->addAction(GetBrowser::getName(), GetBrowser::class);
        $this->addAction(GetFlag::getName(), GetFlag::class);
        $this->addAction(GetImage::getName(), GetImage::class);
        $this->addAction(GetFavicon::getName(), GetFavicon::class);
        $this->addAction(GetQR::getName(), GetQR::class);
        $this->addAction(GetInitials::getName(), GetInitials::class);
        $this->addAction(GetScreenshot::getName(), GetScreenshot::class);
        $this->addAction(GetPhoto::getName(), GetPhoto::class);
        $this->addAction(UpdatePhoto::getName(), UpdatePhoto::class);
        $this->addAction(DeletePhoto::getName(), DeletePhoto::class);
    }
}
