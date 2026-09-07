<?php

namespace Appwrite\Platform\Modules\Avatars\Services;

use Appwrite\Platform\Modules\Avatars\Http\Browsers\Get as GetBrowser;
use Appwrite\Platform\Modules\Avatars\Http\CreditCards\Get as GetCreditCard;
use Appwrite\Platform\Modules\Avatars\Http\Favicon\Get as GetFavicon;
use Appwrite\Platform\Modules\Avatars\Http\Flags\Get as GetFlag;
use Appwrite\Platform\Modules\Avatars\Http\Image\Get as GetImage;
use Appwrite\Platform\Modules\Avatars\Http\Initials\Get as GetInitials;
use Appwrite\Platform\Modules\Avatars\Http\Photo\Get as GetPhoto;
use Appwrite\Platform\Modules\Avatars\Http\QR\Get as GetQR;
use Appwrite\Platform\Modules\Avatars\Http\Screenshots\Get as GetScreenshot;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetCreditCard::getName(), new GetCreditCard());
        $this->addAction(GetBrowser::getName(), new GetBrowser());
        $this->addAction(GetFlag::getName(), new GetFlag());
        $this->addAction(GetImage::getName(), new GetImage());
        $this->addAction(GetFavicon::getName(), new GetFavicon());
        $this->addAction(GetQR::getName(), new GetQR());
        $this->addAction(GetInitials::getName(), new GetInitials());
        $this->addAction(GetScreenshot::getName(), new GetScreenshot());
        $this->addAction(GetPhoto::getName(), new GetPhoto());
    }
}
