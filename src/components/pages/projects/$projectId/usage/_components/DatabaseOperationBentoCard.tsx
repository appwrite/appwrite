'use client'

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
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import { UsageTimeSeriesChartCard } from './UsageTimeSeriesChartCard'
import {
  UsageBreakdownCard,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './UsageMetricCard'

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
  showBreakdown: boolean
  breakdowns: DatabaseBreakdownEntry[]
  databaseLookup: DatabaseBreakdownResourceMap | undefined
  tableLookup?: TableBreakdownResourceMap | undefined
  onRetry: () => void
  onOpenBreakdownDrawer: (payload: DatabaseBreakdownDrawerPayload) => void
  docsHref?: string
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
  showBreakdown,
  breakdowns,
  databaseLookup,
  tableLookup,
  onRetry,
  onOpenBreakdownDrawer,
  docsHref,
}: DatabaseOperationBentoCardProps) {
  const t = useT()
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
        errorTitle={DATABASE_USAGE_ERROR.title}
        errorMessage={DATABASE_USAGE_ERROR.message}
        formatTotal={formatDatabaseOperationsTotal}
        formatValue={formatDatabaseOperationsValue}
        onRetry={onRetry}
      />

      {showBreakdown ? (
        <div className={cn(breakdownRowGridClass, 'border-t border-border')}>
          {breakdowns.map(({ section, items, isLoading, isError }) => (
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
              projectId={projectId}
              isLoading={isLoading}
              isError={isError}
              errorTitle={DATABASE_USAGE_ERROR.title}
              errorMessage={DATABASE_USAGE_ERROR.message}
              formatValue={formatDatabaseOperationsValue}
              onRetry={onRetry}
              onShowMore={() =>
                onOpenBreakdownDrawer({
                  operation,
                  title: `${t(operation === 'reads' ? 'Reads' : 'Writes')} · ${t(section.title)}`,
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
