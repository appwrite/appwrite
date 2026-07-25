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
        $this->addAction(ListMigrations::getName(), new ListMigrations());
        $this->addAction(GetMigration::getName(), new GetMigration());
        $this->addAction(UpdateMigration::getName(), new UpdateMigration());
        $this->addAction(DeleteMigration::getName(), new DeleteMigration());

        // Appwrite source
        $this->addAction(CreateAppwriteMigration::getName(), new CreateAppwriteMigration());
        $this->addAction(GetAppwriteReport::getName(), new GetAppwriteReport());

        // Firebase source
        $this->addAction(CreateFirebaseMigration::getName(), new CreateFirebaseMigration());
        $this->addAction(GetFirebaseReport::getName(), new GetFirebaseReport());

        // Supabase source
        $this->addAction(CreateSupabaseMigration::getName(), new CreateSupabaseMigration());
        $this->addAction(GetSupabaseReport::getName(), new GetSupabaseReport());

        // NHost source
        $this->addAction(CreateNHostMigration::getName(), new CreateNHostMigration());
        $this->addAction(GetNHostReport::getName(), new GetNHostReport());

        // CSV import / export
        $this->addAction(CreateCSVImport::getName(), new CreateCSVImport());
        $this->addAction(CreateCSVExport::getName(), new CreateCSVExport());

        // JSON import / export
        $this->addAction(CreateJSONImport::getName(), new CreateJSONImport());
        $this->addAction(CreateJSONExport::getName(), new CreateJSONExport());
    }
}
