'use client'

import { useCallback, useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import { Download, FileJson, FileText, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  useCountryLookups,
  useProjectRequestsBreakdownDrawer,
  useProjectBandwidthBreakdownDrawer,
  useProjectDatabaseReadsBreakdownDrawer,
  useProjectDatabaseWritesBreakdownDrawer,
  useUsageResourceBreakdownLookups,
} from '@/lib/react-query/hooks'
import { USAGE_BREAKDOWN_DRAWER_LIMIT } from '@/lib/usage/breakdown-limits'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResourceMap } from '@/lib/usage/resolve-table-breakdown-resources'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  OVERVIEW_REQUESTS_ERROR,
} from '../../overview/chart-panel'
import { OverviewChartPanelError } from '../../overview/OverviewChartPanelError'
import {
  UsageBreakdownListSkeleton,
  UsageBreakdownRowsList,
} from './UsageBreakdownRows'
import {
  downloadUsageBreakdownCsv,
  downloadUsageBreakdownJson,
  type UsageBreakdownExportKind,
} from './export-usage-breakdown'
import { formatBandwidthValue } from '@/lib/usage/bandwidth-events'
import { formatRequestsValue } from '@/lib/usage/requests-events'
import { formatDatabaseOperationsValue } from '@/lib/usage/database-usage'

const DATABASE_USAGE_ERROR = {
  title: "Couldn't load database usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type UsageBreakdownDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  dateRange: DateRange | undefined
  title: string
  description?: string
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
  kind?: UsageBreakdownExportKind
  databaseLookup?: DatabaseBreakdownResourceMap | null
  computeLookup?: ComputeBreakdownResourceMap | null
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
}

export function UsageBreakdownDrawer({
  open,
  onOpenChange,
  projectId,
  dateRange,
  title,
  description,
  dimension,
  labelVariant,
  kind = 'requests',
  databaseLookup: databaseLookupProp,
  computeLookup: computeLookupProp,
  storageLookup: storageLookupProp,
  tableLookup: tableLookupProp,
}: UsageBreakdownDrawerProps) {
  const t = useT()
  const { lookups: countryLookups } = useCountryLookups()

  const requestsQuery = useProjectRequestsBreakdownDrawer(
    projectId,
    dateRange,
    dimension,
    open && kind === 'requests',
  )
  const bandwidthQuery = useProjectBandwidthBreakdownDrawer(
    projectId,
    dateRange,
    dimension,
    open && kind === 'bandwidth',
  )
  const databaseReadsQuery = useProjectDatabaseReadsBreakdownDrawer(
    projectId,
    dateRange,
    dimension,
    open && kind === 'database-reads',
  )
  const databaseWritesQuery = useProjectDatabaseWritesBreakdownDrawer(
    projectId,
    dateRange,
    dimension,
    open && kind === 'database-writes',
  )

  const activeQuery =
    kind === 'bandwidth'
      ? bandwidthQuery
      : kind === 'database-reads'
        ? databaseReadsQuery
        : kind === 'database-writes'
          ? databaseWritesQuery
          : requestsQuery
  const {
    data: items = [],
    isLoading,
    isError,
    isFetching,
    refetch,
  } = activeQuery

  const resourceItems = useMemo(
    () =>
      dimension === 'resource' || dimension === 'resourceId' ? items : [],
    [dimension, items],
  )

  const fetchedLookups = useUsageResourceBreakdownLookups(
    projectId,
    resourceItems,
    open &&
      (dimension === 'resource' || dimension === 'resourceId') &&
      resourceItems.length > 0,
  )

  const computeLookup = computeLookupProp ?? fetchedLookups.computeLookup
  const databaseLookup = databaseLookupProp ?? fetchedLookups.databaseLookup
  const storageLookup = storageLookupProp ?? fetchedLookups.storageLookup
  const tableLookup = tableLookupProp ?? fetchedLookups.tableLookup

  const formatValue =
    kind === 'bandwidth'
      ? formatBandwidthValue
      : kind === 'database-reads' || kind === 'database-writes'
        ? formatDatabaseOperationsValue
        : formatRequestsValue
  const errorMeta =
    kind === 'bandwidth'
      ? OVERVIEW_BANDWIDTH_ERROR
      : kind === 'database-reads' || kind === 'database-writes'
        ? DATABASE_USAGE_ERROR
        : OVERVIEW_REQUESTS_ERROR

  const showLeadingIcon =
    (dimension === 'country' && !!countryLookups) ||
    dimension === 'hostname' ||
    dimension === 'service' ||
    ((dimension === 'resourceId' || dimension === 'resource') &&
      (!!databaseLookup ||
        !!computeLookup ||
        !!storageLookup ||
        !!tableLookup))

  const showLoading = isLoading && items.length === 0
  const showEmpty = !showLoading && !isError && items.length === 0
  const canExport = !showLoading && !isError && items.length > 0

  const handleExportJson = useCallback(() => {
    if (!canExport) return
    downloadUsageBreakdownJson(
      items,
      title,
      dimension,
      labelVariant,
      countryLookups,
      kind,
      databaseLookup,
      computeLookup,
      storageLookup,
      tableLookup,
    )
    toast.success(t('Exported as JSON'))
  }, [
    canExport,
    countryLookups,
    computeLookup,
    databaseLookup,
    storageLookup,
    tableLookup,
    dimension,
    items,
    labelVariant,
    kind,
    title,
    t,
  ])

  const handleExportCsv = useCallback(() => {
    if (!canExport) return
    downloadUsageBreakdownCsv(
      items,
      title,
      dimension,
      labelVariant,
      countryLookups,
      kind,
      databaseLookup,
      computeLookup,
      storageLookup,
      tableLookup,
    )
    toast.success(t('Exported as CSV'))
  }, [
    canExport,
    countryLookups,
    computeLookup,
    databaseLookup,
    storageLookup,
    tableLookup,
    dimension,
    items,
    labelVariant,
    kind,
    title,
    t,
  ])

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(title)}
      description={description ? t(description) : t(title)}
      maxWidth="sm:max-w-md"
      headerActions={
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 cursor-pointer text-[12px]"
              disabled={!canExport}
            >
              <Download className="me-1.5 h-3.5 w-3.5" />
              {t('Export')}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-48">
            <DropdownMenuItem
              className="cursor-pointer"
              onClick={handleExportJson}
            >
              <FileJson className="me-2 h-4 w-4" />
              {t('Export as JSON')}
            </DropdownMenuItem>
            <DropdownMenuItem
              className="cursor-pointer"
              onClick={handleExportCsv}
            >
              <FileText className="me-2 h-4 w-4" />
              {t('Export as CSV')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      }
    >
      <div className="flex min-h-0 flex-1 flex-col border-t border-border">
        {description ? (
          <p className="shrink-0 px-6 py-3 text-[12px] leading-relaxed text-muted-foreground">
            {t(description)}
          </p>
        ) : null}

        {isError ? (
          <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-8 text-center">
            <OverviewChartPanelError
              title={t(errorMeta.title)}
              message={t(errorMeta.message)}
              onRetry={() => void refetch()}
            />
          </div>
        ) : showLoading ? (
          <div className="px-6 py-4">
            <UsageBreakdownListSkeleton
              rowCount={10}
              showLeadingIcon={showLeadingIcon}
            />
          </div>
        ) : showEmpty ? (
          <div className="flex flex-1 items-center justify-center px-6 py-8 text-center text-[13px] text-muted-foreground">
            {t('No data for this date range')}
          </div>
        ) : (
          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
            <UsageBreakdownRowsList
              items={items}
              dimension={dimension}
              labelVariant={labelVariant}
              countryLookups={countryLookups}
              databaseLookup={databaseLookup}
              computeLookup={computeLookup}
              storageLookup={storageLookup}
              tableLookup={tableLookup}
              variant="drawer"
              formatValue={formatValue}
            />
          </div>
        )}

        {!showLoading && !isError && items.length > 0 ? (
          <div className="shrink-0 border-t border-border bg-muted/30 px-6 py-3">
            <p className="text-[11px] text-muted-foreground">
              {t('Showing up to')} {USAGE_BREAKDOWN_DRAWER_LIMIT} {t('items')}
              {isFetching ? (
                <Loader2
                  className="ms-1 inline h-3 w-3 animate-spin align-middle"
                  aria-hidden
                />
              ) : null}
            </p>
          </div>
        ) : null}
      </div>
    </BaseDrawer>
  )
}
