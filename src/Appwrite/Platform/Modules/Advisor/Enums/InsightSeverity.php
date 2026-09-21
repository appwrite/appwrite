<?php

namespace Appwrite\Platform\Modules\Advisor\Enums;

enum InsightSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
}
