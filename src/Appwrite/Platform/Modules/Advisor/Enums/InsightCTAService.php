<?php

namespace Appwrite\Platform\Modules\Advisor\Enums;

enum InsightCTAService: string
{
    case DATABASES = 'databases';
    case TABLES_DB = 'tablesDB';
    case DOCUMENTS_DB = 'documentsDB';
    case VECTORS_DB = 'vectorsDB';
}
