'use client'

import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { CountryLookups } from '@/lib/locale/country-lookups'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { USAGE_RESOURCES_BREAKDOWN_TITLE } from '@/lib/usage/usage-resources-breakdown'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResourceMap } from '@/lib/usage/resolve-table-breakdown-resources'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { overviewTopBreakdownListClass } from '../../overview/chart-panel'
import {
  UsageBreakdownListSkeleton,
  UsageBreakdownRowsList,
} from './UsageBreakdownRows'
import {
  UsageBreakdownListEmptyOverlay,
  UsageBreakdownListError,
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from './UsageMetricCard'

type UsageResourceBreakdownCardProps = {
  title?: string
  description: string
  items: UsageBreakdownItem[]
  isLoading: boolean
  isError: boolean
  error?: unknown
  countryLookups?: CountryLookups | null
  databaseLookup?: DatabaseBreakdownResourceMap | null
  computeLookup?: ComputeBreakdownResourceMap | null
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
  errorTitle: string
  errorMessage: string
  formatValue: (value: number) => string
  onRetry?: () => void
  onShowMore?: () => void
  /** When set, used instead of `items.length` for the Show more threshold. */
  showMoreItemCount?: number
  titleAddon?: ReactNode
  embedded?: boolean
  className?: string
}

export function UsageResourceBreakdownCard({
  title = USAGE_RESOURCES_BREAKDOWN_TITLE,
  description,
  items,
  isLoading,
  isError,
  error,
  countryLookups = null,
  databaseLookup,
  computeLookup,
  storageLookup,
  tableLookup,
  errorTitle,
  errorMessage,
  formatValue,
  onRetry,
  onShowMore,
  showMoreItemCount,
  titleAddon,
  embedded = false,
  className,
}: UsageResourceBreakdownCardProps) {
  const t = useT()

  const showLeadingIcon =
    !!(databaseLookup || computeLookup || storageLookup || tableLookup) &&
    items.length > 0
  const showEmptyOverlay = !isLoading && !isError && items.length === 0
  const showShowMore =
    !isError &&
    (showMoreItemCount ?? items.length) >= OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT &&
    !!onShowMore

  const breakdownContent = (
    <>
      <div
        className={cn(
          'flex shrink-0 flex-col gap-3 border-b border-border px-4',
          embedded ? 'py-3' : 'py-4',
        )}
      >
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-1.5">
            <h3
              className={cn(
                'min-w-0 font-medium text-foreground',
                embedded ? 'text-[13px]' : 'text-[14px]',
              )}
            >
              {t(title)}
            </h3>
            {titleAddon}
          </div>
          {showShowMore && onShowMore ? (
            <button
              type="button"
              className="shrink-0 cursor-pointer text-[12px] text-muted-foreground transition-colors hover:text-foreground"
              onClick={onShowMore}
            >
              {t('Show more')}
            </button>
          ) : null}
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col p-4">
        {isError ? (
          <UsageBreakdownListError
            title={errorTitle}
            message={errorMessage}
            error={error}
            onRetry={onRetry}
          />
        ) : isLoading && items.length === 0 ? (
          <UsageBreakdownListSkeleton showLeadingIcon={showLeadingIcon} />
        ) : (
          <div className={cn(overviewTopBreakdownListClass, 'flex-1')}>
            {showEmptyOverlay ? <UsageBreakdownListEmptyOverlay /> : null}
            <UsageBreakdownRowsList
              items={items}
              dimension="resource"
              labelVariant="default"
              countryLookups={countryLookups}
              databaseLookup={databaseLookup}
              computeLookup={computeLookup}
              storageLookup={storageLookup}
              tableLookup={tableLookup}
              variant="card"
              formatValue={formatValue}
            />
          </div>
        )}
      </div>
    </>
  )

  if (embedded) {
    return (
      <div className={cn('flex h-full min-h-0 flex-col', className)}>
        {breakdownContent}
      </div>
    )
  }

  return (
    <UsageMetricCardShell className={cn('h-full', className)}>
      {breakdownContent}
      <UsageMetricCardFooter description={description} />
    </UsageMetricCardShell>
  )
}
