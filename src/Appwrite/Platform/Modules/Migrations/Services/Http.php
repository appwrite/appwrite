<?php

namespace Appwrite\Platform\Modules\Migrations\Services;

use Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite\Create as CreateAppwriteMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite\Report\Get as GetAppwriteReport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\CSV\Exports\Create as CreateCSVExport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\CSV\Imports\Create as CreateCSVImport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Delete as DeleteMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Firebase\Create as CreateFirebaseMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Firebase\Report\Get as GetFirebaseReport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Get as GetMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\JSON\Exports\Create as CreateJSONExport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\JSON\Imports\Create as CreateJSONImport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\NHost\Create as CreateNHostMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\NHost\Report\Get as GetNHostReport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Create as CreateSupabaseMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Report\Get as GetSupabaseReport;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Update as UpdateMigration;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\XList as ListMigrations;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Migrations
        $this->addAction('listMigrations', ListMigrations::class);
        $this->addAction('getMigration', GetMigration::class);
        $this->addAction('retryMigration', UpdateMigration::class);
        $this->addAction('deleteMigration', DeleteMigration::class);

        // Appwrite source
        $this->addAction('createAppwriteMigration', CreateAppwriteMigration::class);
        $this->addAction('getAppwriteReport', GetAppwriteReport::class);

        // Firebase source
        $this->addAction('createFirebaseMigration', CreateFirebaseMigration::class);
        $this->addAction('getFirebaseReport', GetFirebaseReport::class);

        // Supabase source
        $this->addAction('createSupabaseMigration', CreateSupabaseMigration::class);
        $this->addAction('getSupabaseReport', GetSupabaseReport::class);

        // NHost source
        $this->addAction('createNHostMigration', CreateNHostMigration::class);
        $this->addAction('getNHostReport', GetNHostReport::class);

        // CSV import / export
        $this->addAction('createCSVImport', CreateCSVImport::class);
        $this->addAction('createCSVExport', CreateCSVExport::class);

        // JSON import / export
        $this->addAction('createJSONImport', CreateJSONImport::class);
        $this->addAction('createJSONExport', CreateJSONExport::class);
    }
}
