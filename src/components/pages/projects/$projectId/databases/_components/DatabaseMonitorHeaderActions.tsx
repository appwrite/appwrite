import { Link } from '@tanstack/react-router'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { RefreshCw } from 'lucide-react'
import { DatabaseType as ApiDatabaseType } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { DateRangePicker } from '@/components/pages/projects/$projectId/analytics/DateRangePicker'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useProjectDatabase } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import {
  getEffectiveDatabaseSpecIdForMonitoring,
  getSpecOptionById,
  isServerlessDatabaseMonitoring,
} from '@/lib/database-specs'

export function getDefaultMonitorDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
}

type DatabaseMonitorHeaderActionsProps = {
  projectId: string
  databaseId: string
  routeDbKind: DatabaseRouteKind
  dateRange: DateRange
  onDateRangeChange: (range: DateRange | undefined) => void
  onRefresh: () => void
  showSpecActions: boolean
}

export function DatabaseMonitorHeaderActions({
  projectId,
  databaseId,
  routeDbKind,
  dateRange,
  onDateRangeChange,
  onRefresh,
  showSpecActions,
}: DatabaseMonitorHeaderActionsProps) {
  const { database } = useProjectDatabase(projectId, databaseId)
  const { features } = useConsoleProfile()
  const databaseType =
    (database as { databaseType?: ApiDatabaseType } | null)?.databaseType ??
    ApiDatabaseType.Tablesdb

  const specId = getEffectiveDatabaseSpecIdForMonitoring(databaseType)
  const spec = getSpecOptionById(specId)
  const serverless = isServerlessDatabaseMonitoring(databaseType, specId)
  const specLabel = spec?.label ?? specId

  const showUpgradeCta =
    showSpecActions &&
    (features.dedicatedDbsTablesDB ||
      !serverless ||
      databaseType !== ApiDatabaseType.Tablesdb)

  return (
    <div className="flex min-w-0 max-w-full flex-nowrap items-center gap-2 overflow-x-auto sm:gap-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <div className="flex shrink-0 flex-nowrap items-center gap-2">
        <Badge
          variant={serverless ? 'info' : 'success'}
          className="h-[22px] shrink-0 px-1.5 py-0 text-[10px] leading-none"
        >
          {serverless ? 'Serverless' : 'Dedicated'}
        </Badge>
        <span className="max-w-[120px] truncate text-[12px] leading-none text-muted-foreground sm:max-w-[180px]">
          {specLabel}
        </span>
        {showUpgradeCta ? (
          <Button variant="outline" size="sm" className="h-7 shrink-0 px-2 text-[12px]" asChild>
            <Link
              to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
              params={{ projectId, dbKind: routeDbKind, databaseId }}
            >
              {serverless ? 'Upgrade' : 'Change spec'}
            </Link>
          </Button>
        ) : null}
      </div>

      <div className="hidden h-3 w-px shrink-0 self-center bg-border sm:block" aria-hidden />

      <div className="flex shrink-0 items-center gap-2 sm:gap-3">
        <DateRangePicker
          dateRange={dateRange}
          onDateRangeChange={onDateRangeChange}
          className="h-7 min-w-[140px] text-[12px] sm:min-w-[160px]"
        />
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                onClick={onRefresh}
                className="h-7 w-7 shrink-0 p-0"
                type="button"
              >
                <RefreshCw className="h-4 w-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>Refresh</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    </div>
  )
}
