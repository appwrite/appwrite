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
        $this->addAction(ListMigrations::getName(), ListMigrations::class);
        $this->addAction(GetMigration::getName(), GetMigration::class);
        $this->addAction(UpdateMigration::getName(), UpdateMigration::class);
        $this->addAction(DeleteMigration::getName(), DeleteMigration::class);

        // Appwrite source
        $this->addAction(CreateAppwriteMigration::getName(), CreateAppwriteMigration::class);
        $this->addAction(GetAppwriteReport::getName(), GetAppwriteReport::class);

        // Firebase source
        $this->addAction(CreateFirebaseMigration::getName(), CreateFirebaseMigration::class);
        $this->addAction(GetFirebaseReport::getName(), GetFirebaseReport::class);

        // Supabase source
        $this->addAction(CreateSupabaseMigration::getName(), CreateSupabaseMigration::class);
        $this->addAction(GetSupabaseReport::getName(), GetSupabaseReport::class);

        // NHost source
        $this->addAction(CreateNHostMigration::getName(), CreateNHostMigration::class);
        $this->addAction(GetNHostReport::getName(), GetNHostReport::class);

        // CSV import / export
        $this->addAction(CreateCSVImport::getName(), CreateCSVImport::class);
        $this->addAction(CreateCSVExport::getName(), CreateCSVExport::class);

        // JSON import / export
        $this->addAction(CreateJSONImport::getName(), CreateJSONImport::class);
        $this->addAction(CreateJSONExport::getName(), CreateJSONExport::class);
    }
}
