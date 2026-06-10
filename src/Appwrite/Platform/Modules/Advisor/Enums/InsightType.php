<?php

namespace Appwrite\Platform\Modules\Advisor\Enums;

enum InsightType: string
{
    case DATABASE_INDEX = 'databaseIndex';
    case TABLES_DB_INDEX = 'tablesDBIndex';
    case DOCUMENTS_DB_INDEX = 'documentsDBIndex';
    case VECTORS_DB_INDEX = 'vectorsDBIndex';
    case DATABASE_PERFORMANCE = 'databasePerformance';
    case SITE_PERFORMANCE = 'sitePerformance';
    case SITE_ACCESSIBILITY = 'siteAccessibility';
    case SITE_SEO = 'siteSeo';
    case FUNCTION_PERFORMANCE = 'functionPerformance';
}
