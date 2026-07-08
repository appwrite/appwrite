'use client'

import { useMemo } from 'react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
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
import type { UsageResourceBreakdownDimension } from '@/lib/usage/usage-resources-breakdown'
import { splitUsageBreakdownEntries } from '@/lib/usage/usage-resources-breakdown'
import { UsageTimeSeriesChartCard } from './UsageTimeSeriesChartCard'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './UsageMetricCard'
import { UsageResourceBreakdownCard } from './UsageResourceBreakdownCard'

const DATABASE_USAGE_ERROR = {
  title: "Couldn't load database usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

const breakdownRowGridClass =
  'grid grid-cols-1 divide-y divide-border lg:grid-cols-3 lg:divide-x lg:divide-y-0'

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
  databaseLookup: DatabaseBreakdownResourceMap | undefined
  tableLookup?: TableBreakdownResourceMap | undefined
  onRetry: () => void
  onOpenBreakdownDrawer: (payload: DatabaseBreakdownDrawerPayload) => void
  docsHref?: string
}

function breakdownDrawerTitle(
  operation: 'reads' | 'writes',
  section: DatabaseOperationsBreakdownSection,
  t: ReturnType<typeof useT>,
): string {
  const prefix = operation === 'reads' ? t('Reads') : t('Writes')
  return `${prefix} · ${t(section.title)}`
}

function resourcesDrawerTitle(
  operation: 'reads' | 'writes',
  dimension: UsageResourceBreakdownDimension,
  t: ReturnType<typeof useT>,
): string {
  const prefix = operation === 'reads' ? t('Reads') : t('Writes')
  const suffix =
    dimension === 'resourceId' ? t('Resource ID') : t('Resource type')
  return `${prefix} · ${t('Resources')} · ${suffix}`
}

export function DatabaseOperationBentoCard({
  projectId,
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
  databaseLookup,
  tableLookup,
  onRetry,
  onOpenBreakdownDrawer,
  docsHref,
}: DatabaseOperationBentoCardProps) {
  const t = useT()
  const { standardEntries, resourceIdEntry, resourceTypeEntry } = useMemo(
    () => splitUsageBreakdownEntries(breakdowns),
    [breakdowns],
  )

  return (
    <UsageMetricCardShell>
      <UsageTimeSeriesChartCard
        embedded
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
      />

      {showBreakdown ? (
        <div className={cn(breakdownRowGridClass, 'border-t border-border')}>
          {resourceIdEntry && resourceTypeEntry ? (
            <UsageResourceBreakdownCard
              embedded
              description={resourceIdEntry.section.description}
              resourceIdView={{
                items: resourceIdEntry.items,
                isLoading: resourceIdEntry.isLoading,
                isError: resourceIdEntry.isError,
              }}
              resourceTypeView={{
                items: resourceTypeEntry.items,
                isLoading: resourceTypeEntry.isLoading,
                isError: resourceTypeEntry.isError,
              }}
              databaseLookup={databaseLookup}
              tableLookup={tableLookup}
              errorTitle={DATABASE_USAGE_ERROR.title}
              errorMessage={DATABASE_USAGE_ERROR.message}
              formatValue={formatDatabaseOperationsValue}
              onRetry={onRetry}
              onShowMore={(dimension) =>
                onOpenBreakdownDrawer({
                  operation,
                  title: resourcesDrawerTitle(operation, dimension, t),
                  description: resourceIdEntry.section.description,
                  dimension,
                  labelVariant:
                    dimension === 'resourceId' ? 'mono' : 'default',
                })
              }
            />
          ) : null}

          {standardEntries.map(({ section, items, isLoading, isError }) => (
            <UsageBreakdownCard
              key={section.dimension}
              embedded
              title={section.title}
              description={section.description}
              dimension={section.dimension}
              items={items}
              labelVariant={section.labelVariant}
              countryLookups={null}
              databaseLookup={databaseLookup}
              tableLookup={tableLookup}
              isLoading={isLoading}
              isError={isError}
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
          ))}
        </div>
      ) : null}

      <UsageMetricCardFooter description={description} docsHref={docsHref} />
    </UsageMetricCardShell>
  )
}
