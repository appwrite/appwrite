'use client'

import { useMemo, type ReactNode } from 'react'
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
import {
  resolveUsageChartErrorCopy,
  shouldSuppressUsageChartRetry,
} from '@/lib/usage/usage-history-errors'
import { DEFAULT_USAGE_LOG_RETENTION_DAYS } from '@/lib/usage/usage-log-retention'
import { overviewTopBreakdownListClass } from '../../overview/chart-panel'
import { OverviewChartPanelError } from '../../overview/OverviewChartPanelError'
import { UsageChartErrorMessage } from '../../shared/UsageChartErrorMessage'
import {
  UsageBreakdownListSkeleton,
  UsageBreakdownRowsList,
} from './UsageBreakdownRows'
import { UsagePremiumGeoDBCurtain } from './UsagePremiumGeoDBCurtain'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useOptionalUsageFilters } from '../usage-filters-context'
import { isUsageAddonNotFoundError } from '@/lib/usage/usage-history-errors'

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
  error,
  onRetry,
}: {
  title: string
  message: string
  error?: unknown
  onRetry?: () => void
}) {
  const t = useT()
  const usageFilters = useOptionalUsageFilters()
  const resolvedErrorCopy = useMemo(
    () =>
      resolveUsageChartErrorCopy(
        error,
        usageFilters?.usageLogRetentionDays ?? DEFAULT_USAGE_LOG_RETENTION_DAYS,
        { title, message },
      ),
    [error, usageFilters?.usageLogRetentionDays, title, message],
  )
  const showRetry =
    !!onRetry && !shouldSuppressUsageChartRetry(resolvedErrorCopy)

  if (isUsageAddonNotFoundError(error) || resolvedErrorCopy.isAddonNotFound) {
    return (
      <div className={overviewTopBreakdownListClass}>
        <UsagePremiumGeoDBCurtain
          className="absolute inset-0"
          onEnabled={onRetry}
        >
          <UsageBreakdownListSkeleton showLeadingIcon={false} />
        </UsagePremiumGeoDBCurtain>
      </div>
    )
  }

  return (
    <div className={overviewTopBreakdownListClass}>
      <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 px-4 py-6 text-center">
        <OverviewChartPanelError
          title={t(resolvedErrorCopy.title)}
          message={<UsageChartErrorMessage copy={resolvedErrorCopy} />}
          onRetry={showRetry ? onRetry : undefined}
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
  error?: unknown
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
  error,
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
  const showResourceTypeIcons = dimension === 'resourceType'
  const showResourceIcons =
    (dimension === 'resource' || dimension === 'resourceId') &&
    !!(databaseLookup || computeLookup || storageLookup || tableLookup) &&
    items.length > 0
  const showLeadingIcon =
    showCountryFlags ||
    showHostnameFavicons ||
    showServiceIcons ||
    showResourceTypeIcons ||
    showResourceIcons
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
