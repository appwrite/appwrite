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
import { useProjectDatabase, useProject } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import {
  getEffectiveDatabaseSpecIdForMonitoring,
  getSpecOptionById,
  isServerlessDatabaseMonitoring,
} from '@/lib/database-specs'
import { useT } from '@/lib/i18n/translate'

export function getDefaultMonitorDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
}

type DatabaseMonitorHeaderActionsProps = {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
  dateRange: DateRange
  onDateRangeChange: (range: DateRange | undefined) => void
  onRefresh: () => void
  showSpecActions: boolean
}

export function DatabaseMonitorHeaderActions({
  projectId,
  databaseId,
  dbKind,
  dateRange,
  onDateRangeChange,
  onRefresh,
  showSpecActions,
}: DatabaseMonitorHeaderActionsProps) {
  const t = useT()
  const { database } = useProjectDatabase(projectId, databaseId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const supportsDedicatedDatabaseCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)
  const databaseType =
    (database as { databaseType?: ApiDatabaseType } | null)?.databaseType ??
    ApiDatabaseType.Tablesdb

  const specId = getEffectiveDatabaseSpecIdForMonitoring(databaseType)
  const spec = getSpecOptionById(specId)
  const serverless = isServerlessDatabaseMonitoring(databaseType, specId)
  const specLabel = spec?.label ?? specId

  const wouldShowUpgrade =
    showSpecActions &&
    (features.dedicatedDbsTablesDB ||
      !serverless ||
      databaseType !== ApiDatabaseType.Tablesdb)

  const showUpgradeCta =
    wouldShowUpgrade && supportsDedicatedDatabaseCompute
  const showUpgradeComingSoon =
    wouldShowUpgrade && !supportsDedicatedDatabaseCompute

  return (
    <div className="flex min-w-0 max-w-full flex-nowrap items-center gap-2 @[560px]:gap-3">
      <div className="flex shrink-0 flex-nowrap items-center gap-2">
        <Badge
          variant={serverless ? 'info' : 'success'}
          className="h-[22px] shrink-0 px-1.5 py-0 text-[10px] leading-none"
        >
          {serverless ? t('Serverless') : t('Dedicated')}
        </Badge>
        <span className="hidden max-w-[180px] truncate text-[12px] leading-none text-muted-foreground @[480px]:inline">
          {specLabel}
        </span>
        {showUpgradeCta ? (
          <Button
            variant="outline"
            size="sm"
            className="hidden h-7 shrink-0 px-2 text-[12px] @[560px]:inline-flex"
            asChild
          >
            <Link
              to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
              params={{ projectId, dbKind, databaseId }}
            >
              {serverless ? t('Upgrade') : t('Change spec')}
            </Link>
          </Button>
        ) : showUpgradeComingSoon ? (
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="hidden @[560px]:inline-flex">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 shrink-0 px-2 text-[12px]"
                    disabled
                  >
                    {serverless ? t('Upgrade') : t('Change spec')}
                  </Button>
                </span>
              </TooltipTrigger>
              <TooltipContent side="bottom" className="max-w-xs text-[12px]">
                {getDedicatedDatabaseRegionUnavailableDescription()}
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ) : null}
      </div>

      <div
        className="hidden h-3 w-px shrink-0 self-center bg-border @[560px]:block"
        aria-hidden
      />

      <div className="flex shrink-0 items-center gap-2 @[560px]:gap-3">
        <DateRangePicker
          dateRange={dateRange}
          onDateRangeChange={onDateRangeChange}
          className="h-7 min-w-[120px] text-[12px] @[560px]:min-w-[160px]"
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
            <TooltipContent>{t('Refresh')}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    </div>
  )
}
