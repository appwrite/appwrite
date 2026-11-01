<?php

namespace Appwrite\Platform\Modules\Storage\Services;

use Appwrite\Platform\Modules\Storage\Http\Buckets\Create as CreateBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Delete as DeleteBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Create as CreateFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Delete as DeleteFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Download\Get as GetFileDownload;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Get as GetFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Preview\Get as GetFilePreview;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Push\Get as GetFileForPush;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Update as UpdateFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\View\Get as GetFileView;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\XList as ListFiles;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Get as GetBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Update as UpdateBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\XList as ListBuckets;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Buckets
        $this->addAction(CreateBucket::getName(), CreateBucket::class);
        $this->addAction(GetBucket::getName(), GetBucket::class);
        $this->addAction(ListBuckets::getName(), ListBuckets::class);
        $this->addAction(UpdateBucket::getName(), UpdateBucket::class);
        $this->addAction(DeleteBucket::getName(), DeleteBucket::class);

        // Files
        $this->addAction(CreateFile::getName(), CreateFile::class);
        $this->addAction(GetFile::getName(), GetFile::class);
        $this->addAction(ListFiles::getName(), ListFiles::class);
        $this->addAction(UpdateFile::getName(), UpdateFile::class);
        $this->addAction(DeleteFile::getName(), DeleteFile::class);
        $this->addAction(GetFilePreview::getName(), GetFilePreview::class);
        $this->addAction(GetFileDownload::getName(), GetFileDownload::class);
        $this->addAction(GetFileView::getName(), GetFileView::class);
        $this->addAction(GetFileForPush::getName(), GetFileForPush::class);
    }
}
