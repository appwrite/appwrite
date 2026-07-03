<?php

namespace Appwrite\Platform\Modules\Advisor\Enums;

enum ReportType: string
{
    case LIGHTHOUSE = 'lighthouse';
    case AUDIT = 'audit';
    case DATABASE_ANALYZER = 'databaseAnalyzer';
}
