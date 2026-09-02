<?php

namespace Appwrite\Platform\Workers;

/**
 * @deprecated Use {@see StatsCalculations}. Kept so existing `extends` call
 * sites (Cloud's task override) keep resolving after the rename.
 */
class StatsResources extends StatsCalculations
{
}
