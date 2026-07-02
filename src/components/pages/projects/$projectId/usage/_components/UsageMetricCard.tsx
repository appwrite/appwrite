'use client'

import type { ReactNode } from 'react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import type { CountryLookups } from '@/lib/locale/country-lookups'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResourceMap } from '@/lib/usage/resolve-table-breakdown-resources'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { overviewTopBreakdownListClass } from '../../overview/chart-panel'
import { OverviewChartPanelError } from '../../overview/OverviewChartPanelError'
import {
  UsageBreakdownListSkeleton,
  UsageBreakdownRowsList,
} from './UsageBreakdownRows'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

export function UsageMetricCardShell({
  className,
  children,
}: {
  className?: string
  children: ReactNode
}) {
  return (
    <div
      className={cn(
        'flex h-full flex-col overflow-hidden rounded-lg border border-border bg-card',
        className,
      )}
    >
      {children}
    </div>
  )
}

export function UsageMetricCardFooter({
  description,
  docsHref,
}: {
  description: string
  docsHref?: string
}) {
  const t = useT()
  return (
    <div className="mt-auto shrink-0 border-t border-border bg-muted/30 px-4 py-3">
      <p className="text-[12px] leading-relaxed text-muted-foreground">
        {t(description)}
        {docsHref ? (
          <>
            {' '}
            <DocsRouteLink
              href={docsHref}
              className="font-medium text-foreground underline underline-offset-2 hover:text-primary"
            >
              {t('Learn more')}
            </DocsRouteLink>
          </>
        ) : null}
      </p>
    </div>
  )
}

export function UsageBreakdownListEmptyOverlay() {
  const t = useT()
  return (
    <div className="pointer-events-none absolute inset-0 flex items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
      {t('No data for this date range')}
    </div>
  )
}

export function UsageBreakdownListError({
  title,
  message,
  onRetry,
}: {
  title: string
  message: string
  onRetry?: () => void
}) {
  const t = useT()
  return (
    <div className={overviewTopBreakdownListClass}>
      <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 px-4 py-6 text-center">
        <OverviewChartPanelError
          title={t(title)}
          message={t(message)}
          onRetry={onRetry}
        />
      </div>
    </div>
  )
}

type UsageBreakdownCardProps = {
  title: string
  description: string
  dimension: UsageEventBreakdownDimension
  items: UsageBreakdownItem[]
  labelVariant: 'mono' | 'default'
  countryLookups: CountryLookups | null
  databaseLookup?: DatabaseBreakdownResourceMap | null
  computeLookup?: ComputeBreakdownResourceMap | null
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
  isLoading: boolean
  isError: boolean
  errorTitle: string
  errorMessage: string
  formatValue: (value: number) => string
  onRetry?: () => void
  onShowMore?: () => void
  titleAddon?: ReactNode
  /** Renders breakdown panel only (no card shell or footer). */
  embedded?: boolean
  className?: string
}

export function UsageBreakdownCard({
  title,
  description,
  dimension,
  items,
  labelVariant,
  countryLookups,
  databaseLookup,
  computeLookup,
  storageLookup,
  tableLookup,
  isLoading,
  isError,
  errorTitle,
  errorMessage,
  formatValue,
  onRetry,
  onShowMore,
  titleAddon,
  embedded = false,
  className,
}: UsageBreakdownCardProps) {
  const t = useT()
  const showCountryFlags = dimension === 'country' && !!countryLookups
  const showHostnameFavicons = dimension === 'hostname'
  const showServiceIcons = dimension === 'service'
  const showDatabaseIcons =
    dimension === 'resourceId' && !!databaseLookup && items.length > 0
  const showLeadingIcon =
    showCountryFlags ||
    showHostnameFavicons ||
    showServiceIcons ||
    showDatabaseIcons
  const showEmptyOverlay = !isLoading && !isError && items.length === 0
  const showShowMore =
    !isError &&
    items.length >= OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT &&
    !!onShowMore

  const breakdownContent = (
    <>
      <div
        className={cn(
          'flex shrink-0 items-center justify-between gap-3 border-b border-border px-4',
          embedded ? 'py-3' : 'py-4',
        )}
      >
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

      <div className="flex flex-1 flex-col p-4">
        {isError ? (
          <UsageBreakdownListError
            title={errorTitle}
            message={errorMessage}
            onRetry={onRetry}
          />
        ) : isLoading && items.length === 0 ? (
          <UsageBreakdownListSkeleton showLeadingIcon={showLeadingIcon} />
        ) : (
          <div className={overviewTopBreakdownListClass}>
            {showEmptyOverlay ? <UsageBreakdownListEmptyOverlay /> : null}
            <UsageBreakdownRowsList
              items={items}
              dimension={dimension}
              labelVariant={labelVariant}
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
      <div className={cn('flex min-h-0 flex-col', className)}>
        {breakdownContent}
      </div>
    )
  }

  return (
    <UsageMetricCardShell>
      {breakdownContent}
      <UsageMetricCardFooter description={description} />
    </UsageMetricCardShell>
  )
}
