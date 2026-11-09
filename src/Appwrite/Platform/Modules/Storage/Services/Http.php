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
        $this->addAction('createBucket', CreateBucket::class);
        $this->addAction('getBucket', GetBucket::class);
        $this->addAction('listBuckets', ListBuckets::class);
        $this->addAction('updateBucket', UpdateBucket::class);
        $this->addAction('deleteBucket', DeleteBucket::class);

        // Files
        $this->addAction('createFile', CreateFile::class);
        $this->addAction('getFile', GetFile::class);
        $this->addAction('listFiles', ListFiles::class);
        $this->addAction('updateFile', UpdateFile::class);
        $this->addAction('deleteFile', DeleteFile::class);
        $this->addAction('getFilePreview', GetFilePreview::class);
        $this->addAction('getFileDownload', GetFileDownload::class);
        $this->addAction('getFileView', GetFileView::class);
        $this->addAction('getFileForPush', GetFileForPush::class);
    }
}
