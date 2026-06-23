'use client'

import { useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Flag } from '@appwrite.io/console'
import { ChevronRight, Globe } from 'lucide-react'
import { cn, truncateMiddle } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'
import { compactUsagePathIds } from '@/lib/usage/format-usage-path'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import { formatRequestsValue } from '@/lib/usage/requests-events'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import {
  resolveCountryCode,
  resolveCountryDisplayName,
  type CountryLookups,
} from '@/lib/locale/country-lookups'
import {
  formatUsageServiceLabel,
  getUsageServiceIcon,
} from '@/lib/usage/appwrite-service-icons'
import {
  getComputeBreakdownResourceRoute,
  getComputeBreakdownResourceTypeLabel,
  resolveComputeBreakdownResource,
  type ComputeBreakdownResourceMap,
} from '@/lib/usage/resolve-compute-breakdown-resources'
import {
  getDatabaseBreakdownResourceRoute,
  resolveDatabaseBreakdownResource,
  type DatabaseBreakdownResourceMap,
} from '@/lib/usage/resolve-database-breakdown-resources'
import { DatabaseTypeIcon } from '../../databases/_components/DatabaseTypeIcon'
import {
  overviewTopBreakdownListClass,
  overviewTopBreakdownRowClass,
} from '../../overview/chart-panel'
import { Skeleton } from '@/components/ui/skeleton'

export const PATH_DISPLAY_MAX = 42

export const breakdownLeadingIconFrameClass =
  'flex h-4 w-4 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background'

/** Rows rendered per breakdown card — matches usage API limit. */
export const REQUESTS_BREAKDOWN_ROW_COUNT = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT

const COUNTRY_FLAG_FETCH_PX = 40

export function formatBreakdownLabel(
  label: string,
  labelVariant: 'mono' | 'default',
  dimension: UsageEventBreakdownDimension,
  countryLookups: CountryLookups | null,
  databaseLookup?: DatabaseBreakdownResourceMap | null,
  computeLookup?: ComputeBreakdownResourceMap | null,
): string {
  if (dimension === 'resourceId') {
    const computeResource = resolveComputeBreakdownResource(
      label,
      computeLookup,
    )
    if (computeResource) return computeResource.name
    const resource = resolveDatabaseBreakdownResource(label, databaseLookup)
    if (resource) return resource.name
  }
  if (dimension === 'country' && countryLookups) {
    return resolveCountryDisplayName(label, countryLookups)
  }
  if (dimension === 'service') {
    return formatUsageServiceLabel(label)
  }
  if (labelVariant === 'mono') {
    return truncateMiddle(compactUsagePathIds(label), PATH_DISPLAY_MAX)
  }
  return label
}

export function normalizeHostnameForFavicon(hostname: string): string | null {
  const trimmed = hostname.trim()
  if (!trimmed || trimmed === 'Unknown') return null

  const withoutScheme = trimmed.replace(/^https?:\/\//i, '').split('/')[0] ?? ''
  if (!withoutScheme.includes('.')) return null

  return withoutScheme
}

function CountryFlagIcon({ countryCode }: { countryCode: string }) {
  const [failed, setFailed] = useState(false)
  const normalizedCode = countryCode.trim().toLowerCase()

  if (failed || normalizedCode.length !== 2) {
    return (
      <div
        className={cn(
          breakdownLeadingIconFrameClass,
          'border-transparent bg-transparent',
        )}
      >
        <Globe
          className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
          aria-hidden
        />
      </div>
    )
  }

  const flagUrl = sdk.forConsole.avatars.getFlag({
    code: normalizedCode as Flag,
    width: COUNTRY_FLAG_FETCH_PX,
    height: COUNTRY_FLAG_FETCH_PX,
    quality: 100,
  })

  return (
    <div className={breakdownLeadingIconFrameClass} aria-hidden>
      <img
        src={flagUrl}
        alt=""
        className="h-full w-full object-cover"
        onError={() => setFailed(true)}
      />
    </div>
  )
}

function HostnameFaviconIcon({ hostname }: { hostname: string }) {
  const [failed, setFailed] = useState(false)
  const normalizedHostname = normalizeHostnameForFavicon(hostname)

  if (!normalizedHostname || failed) {
    return (
      <div
        className={cn(
          breakdownLeadingIconFrameClass,
          'border-transparent bg-transparent',
        )}
      >
        <Globe
          className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
          aria-hidden
        />
      </div>
    )
  }

  return (
    <div className={breakdownLeadingIconFrameClass} aria-hidden>
      <img
        src={sdk.forConsole.avatars.getFavicon({
          url: `https://${normalizedHostname}`,
        })}
        alt=""
        className="h-full w-full object-contain p-0.5"
        onError={() => setFailed(true)}
      />
    </div>
  )
}

function UsageServiceIcon({ service }: { service: string }) {
  const Icon = getUsageServiceIcon(service)

  return (
    <div
      className={cn(
        breakdownLeadingIconFrameClass,
        'border-transparent bg-muted/30',
      )}
      aria-hidden
    >
      <Icon className="h-3 w-3 text-muted-foreground" />
    </div>
  )
}

type UsageBreakdownRowProps = {
  item: UsageBreakdownItem
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
  countryLookups: CountryLookups | null
  databaseLookup?: DatabaseBreakdownResourceMap | null
  computeLookup?: ComputeBreakdownResourceMap | null
  projectId?: string
  maxCount: number
  formatValue?: (value: number) => string
}

export function UsageBreakdownRow({
  item,
  dimension,
  labelVariant,
  countryLookups,
  databaseLookup,
  computeLookup,
  projectId,
  maxCount,
  formatValue = formatRequestsValue,
}: UsageBreakdownRowProps) {
  const showCountryFlags = dimension === 'country' && !!countryLookups
  const showHostnameFavicons = dimension === 'hostname'
  const showServiceIcons = dimension === 'service'
  const computeResource =
    dimension === 'resourceId'
      ? resolveComputeBreakdownResource(item.label, computeLookup)
      : undefined
  const databaseResource =
    dimension === 'resourceId' && !computeResource
      ? resolveDatabaseBreakdownResource(item.label, databaseLookup)
      : undefined
  const showDatabaseIcons = !!databaseResource

  const displayLabel = formatBreakdownLabel(
    item.label,
    labelVariant,
    dimension,
    countryLookups,
    databaseLookup,
    computeLookup,
  )
  const countryCode =
    showCountryFlags && countryLookups
      ? resolveCountryCode(item.label, countryLookups)
      : null
  const showFavicon =
    showHostnameFavicons && normalizeHostnameForFavicon(item.label) !== null
  const databaseRoute =
    databaseResource && projectId
      ? getDatabaseBreakdownResourceRoute(projectId, databaseResource)
      : null
  const computeRoute =
    computeResource && projectId
      ? getComputeBreakdownResourceRoute(projectId, computeResource)
      : null

  return (
    <div
      className={cn(
        overviewTopBreakdownRowClass,
        'group relative overflow-hidden transition-colors hover:bg-accent/50',
      )}
    >
      <div
        className="absolute inset-y-0 left-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
        style={{ width: `${(item.count / maxCount) * 100}%` }}
      />
      <div className="relative flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
        {showCountryFlags ? (
          <CountryFlagIcon countryCode={countryCode ?? item.label} />
        ) : showFavicon ? (
          <HostnameFaviconIcon hostname={item.label} />
        ) : showServiceIcons ? (
          <UsageServiceIcon service={item.label} />
        ) : showDatabaseIcons ? (
          <div className={breakdownLeadingIconFrameClass} aria-hidden>
            <DatabaseTypeIcon
              apiType={databaseResource.databaseType}
              className="h-3 w-3"
            />
          </div>
        ) : null}
        {computeRoute ? (
          <div className="flex min-w-0 flex-1 items-center gap-1 overflow-hidden">
            <span className="shrink-0 text-[11px] text-muted-foreground">
              {getComputeBreakdownResourceTypeLabel(computeResource!.type)}
            </span>
            <ChevronRight
              className="h-3 w-3 shrink-0 text-muted-foreground/70"
              aria-hidden
            />
            <Link
              to={computeRoute.to}
              params={computeRoute.params}
              className="min-w-0 truncate text-[12px] font-medium text-foreground transition-colors hover:text-primary"
              title={`${getComputeBreakdownResourceTypeLabel(computeResource!.type)} / ${computeResource!.name}`}
            >
              {computeResource!.name}
            </Link>
          </div>
        ) : databaseRoute ? (
          <Link
            to={databaseRoute.to}
            params={databaseRoute.params}
            className="min-w-0 flex-1 truncate text-[12px] font-medium text-foreground transition-colors hover:text-primary"
            title={databaseResource!.name}
          >
            {databaseResource!.name}
          </Link>
        ) : (
          <span
            className={cn(
              'min-w-0 flex-1 truncate text-[12px] text-foreground/70',
              labelVariant === 'mono' &&
                dimension !== 'country' &&
                dimension !== 'hostname' &&
                dimension !== 'service' &&
                'font-mono',
            )}
            title={displayLabel}
          >
            {displayLabel}
          </span>
        )}
        <span className="shrink-0 text-[12px] font-medium tabular-nums text-muted-foreground">
          {formatValue(item.count)}
        </span>
      </div>
    </div>
  )
}

export function UsageBreakdownRowSkeleton({
  showLeadingIcon = false,
}: {
  showLeadingIcon?: boolean
}) {
  return (
    <div className={overviewTopBreakdownRowClass}>
      {showLeadingIcon ? (
        <Skeleton className="h-4 w-4 shrink-0 rounded-sm" />
      ) : null}
      <Skeleton className="h-3 min-w-0 flex-1 rounded-sm" />
      <Skeleton className="h-3 w-14 shrink-0 rounded-sm" />
    </div>
  )
}

export function UsageBreakdownListSkeleton({
  rowCount = REQUESTS_BREAKDOWN_ROW_COUNT,
  showLeadingIcon = false,
}: {
  rowCount?: number
  showLeadingIcon?: boolean
}) {
  return (
    <div
      className={overviewTopBreakdownListClass}
      aria-busy="true"
      aria-label="Loading breakdown"
    >
      {Array.from({ length: rowCount }).map((_, index) => (
        <UsageBreakdownRowSkeleton
          key={index}
          showLeadingIcon={showLeadingIcon}
        />
      ))}
    </div>
  )
}

type UsageBreakdownRowsListProps = {
  items: UsageBreakdownItem[]
  dimension: UsageEventBreakdownDimension
  labelVariant: 'mono' | 'default'
  countryLookups: CountryLookups | null
  databaseLookup?: DatabaseBreakdownResourceMap | null
  computeLookup?: ComputeBreakdownResourceMap | null
  projectId?: string
  variant?: 'card' | 'drawer'
  className?: string
  formatValue?: (value: number) => string
}

export function UsageBreakdownRowsList({
  items,
  dimension,
  labelVariant,
  countryLookups,
  databaseLookup,
  computeLookup,
  projectId,
  variant = 'card',
  className,
  formatValue,
}: UsageBreakdownRowsListProps) {
  const maxCount = Math.max(...items.map((item) => item.count), 1)

  const itemSlots = useMemo(() => {
    if (variant === 'drawer') return items
    return Array.from(
      { length: REQUESTS_BREAKDOWN_ROW_COUNT },
      (_, index) => items[index] ?? null,
    )
  }, [items, variant])

  return (
    <div
      className={cn('flex w-full min-w-0 flex-col gap-1', className)}
    >
      {itemSlots.map((item, index) => {
        if (!item) {
          if (variant === 'drawer') return null
          return (
            <div
              key={`empty-${index}`}
              className={cn(overviewTopBreakdownRowClass, 'invisible')}
              aria-hidden
            />
          )
        }

        return (
          <UsageBreakdownRow
            key={item.id}
            item={item}
            dimension={dimension}
            labelVariant={labelVariant}
            countryLookups={countryLookups}
            databaseLookup={databaseLookup}
            computeLookup={computeLookup}
            projectId={projectId}
            maxCount={maxCount}
            formatValue={formatValue}
          />
        )
      })}
    </div>
  )
}
