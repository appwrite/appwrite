'use client'

import { useId, useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import { useT } from '@/lib/i18n/translate'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { useCountryLookups } from '@/lib/react-query/hooks'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResourceMap } from '@/lib/usage/resolve-table-breakdown-resources'
import {
  formatDatabaseOperationsTotal,
  formatDatabaseOperationsValue,
} from '@/lib/usage/database-usage'
import type {
  DatabaseReadsBreakdownQueryEntry,
  DatabaseWritesBreakdownQueryEntry,
} from '@/lib/react-query/hooks/usage-events'
import type { DatabaseOperationsBreakdownSection } from '@/lib/usage/database-operations-breakdowns'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import { splitUsageBreakdownEntries } from '@/lib/usage/usage-resources-breakdown'
import { UsageTimeSeriesChartCard } from './UsageTimeSeriesChartCard'
import { UsageBreakdownCard } from './UsageMetricCard'
import { UsageResourceBreakdownCard } from './UsageResourceBreakdownCard'

const DATABASE_USAGE_ERROR = {
  title: "Couldn't load database usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type DatabaseBreakdownEntry =
  | DatabaseReadsBreakdownQueryEntry
  | DatabaseWritesBreakdownQueryEntry

type DatabaseBreakdownDrawerPayload = {
  operation: 'reads' | 'writes'
  title: string
  description: string
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
}

type DatabaseOperationBentoCardProps = {
  projectId: string
  operation: 'reads' | 'writes'
  title: string
  description: string
  unitLabel: string
  chartGradientId: string
  chartPoints: { date: string; day: Date; total: number }[]
  total: number
  changePercent: number
  isLoading: boolean
  isError: boolean
  queryError?: unknown
  showBreakdown: boolean
  breakdowns: DatabaseBreakdownEntry[]
  computeLookup?: ComputeBreakdownResourceMap | undefined
  databaseLookup: DatabaseBreakdownResourceMap | undefined
  storageLookup?: StorageBreakdownResourceMap | undefined
  tableLookup?: TableBreakdownResourceMap | undefined
  onRetry: () => void
  onOpenBreakdownDrawer: (payload: DatabaseBreakdownDrawerPayload) => void
  docsHref?: string
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
}

function breakdownDrawerTitle(
  operation: 'reads' | 'writes',
  section: DatabaseOperationsBreakdownSection,
  t: ReturnType<typeof useT>,
): string {
  const prefix = operation === 'reads' ? t('Reads') : t('Writes')
  return `${prefix} · ${t(section.title)}`
}

/**
 * Chart + breakdown grid for database reads or writes.
 * Chart and its breakdowns are grouped as one section so they stay visually
 * tied together (unlike Requests/Bandwidth, Databases has two metric groups).
 */
export function DatabaseOperationBentoCard({
  projectId: _projectId,
  operation,
  title,
  description,
  unitLabel,
  chartGradientId,
  chartPoints,
  total,
  changePercent,
  isLoading,
  isError,
  queryError,
  showBreakdown,
  breakdowns,
  computeLookup,
  databaseLookup,
  storageLookup,
  tableLookup,
  onRetry,
  onOpenBreakdownDrawer,
  docsHref,
  dateRange,
  chartInterval,
}: DatabaseOperationBentoCardProps) {
  const t = useT()
  const breakdownHeadingId = useId()
  const { lookups: countryLookups } = useCountryLookups()

  const { standardEntries, resourceEntry } = useMemo(
    () => splitUsageBreakdownEntries(breakdowns),
    [breakdowns],
  )

  const breakdownHeading =
    operation === 'reads' ? t('Reads breakdown') : t('Writes breakdown')

  return (
    <section className="space-y-4 border-b border-border pb-10 last:border-b-0 last:pb-0">
      <UsageTimeSeriesChartCard
        title={title}
        description={description}
        unitLabel={unitLabel}
        chartGradientId={chartGradientId}
        total={total}
        changePercent={changePercent}
        chartPoints={chartPoints}
        isLoading={isLoading}
        isError={isError}
        queryError={queryError}
        errorTitle={DATABASE_USAGE_ERROR.title}
        errorMessage={DATABASE_USAGE_ERROR.message}
        formatTotal={formatDatabaseOperationsTotal}
        formatValue={formatDatabaseOperationsValue}
        onRetry={onRetry}
        docsHref={docsHref}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />

      {showBreakdown ? (
        <div className="space-y-3" aria-labelledby={breakdownHeadingId}>
          <div className="flex items-center gap-3 pt-1">
            <h3
              id={breakdownHeadingId}
              className="shrink-0 text-[13px] font-semibold text-foreground"
            >
              {breakdownHeading}
            </h3>
            <div className="h-px min-w-0 flex-1 bg-border" aria-hidden />
          </div>

          <div className="grid items-stretch gap-4 lg:grid-cols-2">
            {standardEntries.map(({ section, items, isLoading, isError, error }) => (
              <div
                key={section.dimension}
                className="flex h-full min-h-0 flex-col"
              >
                <UsageBreakdownCard
                  title={section.title}
                  description={section.description}
                  dimension={section.dimension}
                  items={items}
                  labelVariant={section.labelVariant}
                  countryLookups={countryLookups}
                  computeLookup={computeLookup}
                  databaseLookup={databaseLookup}
                  storageLookup={storageLookup}
                  tableLookup={tableLookup}
                  isLoading={isLoading}
                  isError={isError}
                  error={error}
                  errorTitle={DATABASE_USAGE_ERROR.title}
                  errorMessage={DATABASE_USAGE_ERROR.message}
                  formatValue={formatDatabaseOperationsValue}
                  onRetry={onRetry}
                  onShowMore={() =>
                    onOpenBreakdownDrawer({
                      operation,
                      title: breakdownDrawerTitle(operation, section, t),
                      description: section.description,
                      dimension: section.dimension,
                      labelVariant: section.labelVariant,
                    })
                  }
                />
              </div>
            ))}

            {resourceEntry ? (
              <div className="flex h-full min-h-0 flex-col">
                <UsageResourceBreakdownCard
                  description={resourceEntry.section.description}
                  items={resourceEntry.items}
                  isLoading={resourceEntry.isLoading}
                  isError={resourceEntry.isError}
                  error={resourceEntry.error}
                  countryLookups={countryLookups}
                  computeLookup={computeLookup}
                  databaseLookup={databaseLookup}
                  storageLookup={storageLookup}
                  tableLookup={tableLookup}
                  errorTitle={DATABASE_USAGE_ERROR.title}
                  errorMessage={DATABASE_USAGE_ERROR.message}
                  formatValue={formatDatabaseOperationsValue}
                  onRetry={onRetry}
                  onShowMore={() =>
                    onOpenBreakdownDrawer({
                      operation,
                      title: breakdownDrawerTitle(
                        operation,
                        resourceEntry.section,
                        t,
                      ),
                      description: resourceEntry.section.description,
                      dimension: 'resource',
                      labelVariant: 'default',
                    })
                  }
                />
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </section>
  )
}
