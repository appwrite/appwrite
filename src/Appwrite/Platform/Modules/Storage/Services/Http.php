<?php

namespace Appwrite\Platform\Modules\Storage\Services;

use Appwrite\Platform\Modules\Storage\Http\Buckets\Create as CreateBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Delete as DeleteBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Get as GetBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Update as UpdateBucket;
use Appwrite\Platform\Modules\Storage\Http\Buckets\XList as ListBuckets;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Create as CreateFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Delete as DeleteFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Get as GetFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Preview\Get as GetFilePreview;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Download\Get as GetFileDownload;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\View\Get as GetFileView;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Push\Get as GetFileForPush;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Update as UpdateFile;
use Appwrite\Platform\Modules\Storage\Http\Buckets\Files\XList as ListFiles;
use Appwrite\Platform\Modules\Storage\Http\Usage\XList as ListUsage;
use Appwrite\Platform\Modules\Storage\Http\Usage\Get as GetBucketUsage;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Buckets
        $this->addAction(CreateBucket::getName(), new CreateBucket());
        $this->addAction(GetBucket::getName(), new GetBucket());
        $this->addAction(ListBuckets::getName(), new ListBuckets());
        $this->addAction(UpdateBucket::getName(), new UpdateBucket());
        $this->addAction(DeleteBucket::getName(), new DeleteBucket());

        // Files
        $this->addAction(CreateFile::getName(), new CreateFile());
        $this->addAction(GetFile::getName(), new GetFile());
        $this->addAction(ListFiles::getName(), new ListFiles());
        $this->addAction(UpdateFile::getName(), new UpdateFile());
        $this->addAction(DeleteFile::getName(), new DeleteFile());
        $this->addAction(GetFilePreview::getName(), new GetFilePreview());
        $this->addAction(GetFileDownload::getName(), new GetFileDownload());
        $this->addAction(GetFileView::getName(), new GetFileView());
        $this->addAction(GetFileForPush::getName(), new GetFileForPush());

        // Usage
        $this->addAction(ListUsage::getName(), new ListUsage());
        $this->addAction(GetBucketUsage::getName(), new GetBucketUsage());
    }
}
