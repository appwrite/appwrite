'use client'

import { useMemo, useState } from 'react'
import { Flag } from '@appwrite.io/console'
import { ChevronRight, Globe } from 'lucide-react'
import { useOptionalUsageFilters } from '@/components/pages/projects/$projectId/usage/usage-filters-context'
import { HostnameFaviconIcon } from '@/components/global/shared/HostnameFaviconIcon'
import { translate, useT } from '@/lib/i18n/translate'
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
  getUsageResourceTypeIcon,
  getUsageServiceIcon,
} from '@/lib/usage/appwrite-service-icons'
import {
  getComputeBreakdownResourceTypeLabel,
  resolveComputeBreakdownResource,
  type ComputeBreakdownResourceMap,
} from '@/lib/usage/resolve-compute-breakdown-resources'
import {
  getStorageBreakdownResourceTypeLabel,
  resolveStorageBreakdownResource,
  type StorageBreakdownResourceMap,
} from '@/lib/usage/resolve-storage-breakdown-resources'
import {
  getDatabaseBreakdownServiceLabel,
  resolveDatabaseBreakdownResource,
  type DatabaseBreakdownResourceMap,
} from '@/lib/usage/resolve-database-breakdown-resources'
import {
  getTableBreakdownResourceTypeLabel,
  resolveTableBreakdownResource,
  type TableBreakdownResourceMap,
} from '@/lib/usage/resolve-table-breakdown-resources'
import { DatabaseTypeIcon } from '../../databases/_components/DatabaseTypeIcon'
import {
  overviewTopBreakdownListClass,
  overviewTopBreakdownRowClass,
} from '../../overview/chart-panel'
import { Skeleton } from '@/components/ui/skeleton'
import { Badge } from '@/components/ui/badge'
import { FORM_FIELD_TYPE_PILL_CLASS } from '@/lib/api-explorer/form-field-type-badge'
import { getHttpStatusCodeBadgeVariant } from '@/lib/http-status-code'
import {
  formatHttpMethodBadgeLabel,
  getHttpMethodBadgeVariant,
} from '@/lib/http-method-badge'
import {
  formatUsageResourceTypeLabel,
  getUsageResourceFilterEntries,
  resolveUsageResourceBreakdownItem,
  type UsageBreakdownFilterEntry,
} from '@/lib/usage/usage-resource-filters'

export const PATH_DISPLAY_MAX = 42

export const breakdownLeadingIconFrameClass =
  'flex h-4 w-4 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background'

/** Rows rendered per breakdown card - matches usage API limit. */
export const REQUESTS_BREAKDOWN_ROW_COUNT = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT

const COUNTRY_FLAG_FETCH_PX = 40

function translateUnknownBreakdownLabel(value: string): string {
  return value.trim() === 'Unknown' ? translate('Unknown') : value
}

export function formatBreakdownLabel(
  item: UsageBreakdownItem,
  labelVariant: 'mono' | 'default',
  dimension: UsageEventBreakdownDimension,
  countryLookups: CountryLookups | null,
  databaseLookup?: DatabaseBreakdownResourceMap | null,
  computeLookup?: ComputeBreakdownResourceMap | null,
  storageLookup?: StorageBreakdownResourceMap | null,
  tableLookup?: TableBreakdownResourceMap | null,
): string {
  const label = item.label

  if (dimension === 'resource') {
    const resolved = resolveUsageResourceBreakdownItem(item, {
      databaseLookup,
      computeLookup,
      storageLookup,
      tableLookup,
    })
    if (!resolved.name) {
      return resolved.typeLabel
    }
    return `${resolved.typeLabel} / ${resolved.name}`
  }
  if (dimension === 'resourceId') {
    const computeResource = resolveComputeBreakdownResource(
      label,
      computeLookup,
    )
    if (computeResource) {
      return `${getComputeBreakdownResourceTypeLabel(computeResource.type)} / ${computeResource.name}`
    }
    const storageResource = resolveStorageBreakdownResource(label, storageLookup)
    if (storageResource) {
      return `${getStorageBreakdownResourceTypeLabel()} / ${storageResource.name}`
    }
    const tableResource = resolveTableBreakdownResource(label, tableLookup)
    if (tableResource) {
      return `${getTableBreakdownResourceTypeLabel(tableResource.databaseType)} / ${tableResource.name}`
    }
    const resource = resolveDatabaseBreakdownResource(label, databaseLookup)
    if (resource) {
      return `${getDatabaseBreakdownServiceLabel(resource.databaseType)} / ${resource.name}`
    }
  }
  if (dimension === 'country' && countryLookups) {
    return translateUnknownBreakdownLabel(
      resolveCountryDisplayName(label, countryLookups),
    )
  }
  if (dimension === 'service') {
    return translateUnknownBreakdownLabel(formatUsageServiceLabel(label))
  }
  if (dimension === 'resourceType') {
    return translateUnknownBreakdownLabel(formatUsageResourceTypeLabel(label))
  }
  if (labelVariant === 'mono') {
    return truncateMiddle(compactUsagePathIds(label), PATH_DISPLAY_MAX)
  }
  return translateUnknownBreakdownLabel(label)
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

function UsageResourceTypeIcon({ resourceType }: { resourceType: string }) {
  const Icon = getUsageResourceTypeIcon(resourceType)

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

type BreakdownResourceRowLabelProps = {
  typeLabel: string
  name?: string
  fullTitle?: string
}

function BreakdownResourceRowLabel({
  typeLabel,
  name,
  fullTitle,
}: BreakdownResourceRowLabelProps) {
  const t = useT()
  const hasName = !!name?.trim()
  const title = fullTitle ?? (hasName ? `${typeLabel} / ${name}` : typeLabel)

  if (!hasName) {
    return (
      <span
        className="min-w-0 flex-1 truncate text-[12px] font-medium text-foreground/70"
        title={title}
      >
        {t(typeLabel)}
      </span>
    )
  }

  return (
    <div className="flex min-w-0 flex-1 items-center gap-1 overflow-hidden">
      <span className="shrink-0 text-[11px] text-muted-foreground">
        {t(typeLabel)}
      </span>
      <ChevronRight
        className="h-3 w-3 shrink-0 text-muted-foreground/70"
        aria-hidden
      />
      <span
        className="min-w-0 truncate text-[12px] font-medium text-foreground/70"
        title={title}
      >
        {name}
      </span>
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
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
  maxCount: number
  formatValue?: (value: number) => string
  onAddFilter?: (filters: UsageBreakdownFilterEntry[]) => void
}

export function UsageBreakdownRow({
  item,
  dimension,
  labelVariant,
  countryLookups,
  databaseLookup,
  computeLookup,
  storageLookup,
  tableLookup,
  maxCount,
  formatValue = formatRequestsValue,
  onAddFilter,
}: UsageBreakdownRowProps) {
  const showCountryFlags = dimension === 'country' && !!countryLookups
  const showHostnameFavicons = dimension === 'hostname'
  const showServiceIcons = dimension === 'service'
  const showResourceTypeIcons = dimension === 'resourceType'
  const showStatusBadges = dimension === 'status'
  const showMethodBadges = dimension === 'method'
  const resolvedResource =
    dimension === 'resource'
      ? resolveUsageResourceBreakdownItem(item, {
          databaseLookup,
          computeLookup,
          storageLookup,
          tableLookup,
        })
      : null
  const computeResource =
    dimension === 'resourceId'
      ? resolveComputeBreakdownResource(item.label, computeLookup)
      : resolvedResource?.computeResource
  const storageResource =
    dimension === 'resourceId' && !computeResource
      ? resolveStorageBreakdownResource(item.label, storageLookup)
      : resolvedResource?.storageResource
  const tableResource =
    dimension === 'resourceId' && !computeResource && !storageResource
      ? resolveTableBreakdownResource(item.label, tableLookup)
      : resolvedResource?.tableResource
  const databaseResource =
    dimension === 'resourceId' &&
    !computeResource &&
    !storageResource &&
    !tableResource
      ? resolveDatabaseBreakdownResource(item.label, databaseLookup)
      : resolvedResource?.databaseResource
  const showDatabaseIcons = !!databaseResource
  const showResourceBreakdownLabel = dimension === 'resource' && !!resolvedResource

  const displayLabel = formatBreakdownLabel(
    item,
    labelVariant,
    dimension,
    countryLookups,
    databaseLookup,
    computeLookup,
    storageLookup,
    tableLookup,
  )
  const countryCode =
    showCountryFlags && countryLookups
      ? resolveCountryCode(item.label, countryLookups)
      : null
  const showFavicon = showHostnameFavicons
  const canAddFilter = !!onAddFilter && !!item.label.trim()
  const handleAddFilter = () => {
    if (!canAddFilter || !onAddFilter) return

    if (dimension === 'resourceId' || dimension === 'resource') {
      onAddFilter(
        getUsageResourceFilterEntries(
          item.resourceId ?? item.label,
          {
            computeResource,
            storageResource,
            tableResource,
            databaseResource,
          },
          item.resourceType,
        ),
      )
      return
    }

    if (dimension === 'sdk') {
      const filters: UsageBreakdownFilterEntry[] = []
      const sdk = item.sdk?.trim()
      const sdkVersion = item.sdkVersion?.trim()
      if (sdk) {
        filters.push({ dimension: 'sdk', value: sdk })
      }
      if (sdkVersion) {
        filters.push({ dimension: 'sdkVersion', value: sdkVersion })
      }
      if (filters.length > 0) {
        onAddFilter(filters)
        return
      }
    }

    onAddFilter([{ dimension, value: item.label }])
  }

  return (
    <div
      className={cn(
        overviewTopBreakdownRowClass,
        'group relative overflow-hidden transition-colors hover:bg-accent/50',
        canAddFilter && 'cursor-pointer',
      )}
      onClick={canAddFilter ? handleAddFilter : undefined}
      onKeyDown={
        canAddFilter
          ? (event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                handleAddFilter()
              }
            }
          : undefined
      }
      role={canAddFilter ? 'button' : undefined}
      tabIndex={canAddFilter ? 0 : undefined}
      title={canAddFilter ? `Filter by ${displayLabel}` : displayLabel}
    >
      <div
        className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
        style={{ width: `${(item.count / maxCount) * 100}%` }}
      />
      <div className="relative flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
        <div className="flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
          {showCountryFlags ? (
            <CountryFlagIcon countryCode={countryCode ?? item.label} />
          ) : showFavicon ? (
            <HostnameFaviconIcon hostname={item.label} />
          ) : showServiceIcons ? (
            <UsageServiceIcon service={item.label} />
          ) : showResourceTypeIcons ? (
            <UsageResourceTypeIcon resourceType={item.label} />
          ) : showDatabaseIcons ? (
            <div className={breakdownLeadingIconFrameClass} aria-hidden>
              <DatabaseTypeIcon
                apiType={databaseResource.databaseType}
                className="h-3 w-3"
              />
            </div>
          ) : computeResource || storageResource || tableResource ? (
            <UsageResourceTypeIcon
              resourceType={
                computeResource
                  ? computeResource.type
                  : storageResource
                    ? 'bucket'
                    : `database/${tableResource!.databaseId}/table`
              }
            />
          ) : resolvedResource &&
            !databaseResource &&
            item.resourceType?.trim() ? (
            <UsageResourceTypeIcon resourceType={item.resourceType} />
          ) : null}
          {showResourceBreakdownLabel && resolvedResource ? (
            <BreakdownResourceRowLabel
              typeLabel={resolvedResource.typeLabel}
              name={
                resolvedResource.name
                  ? (item.resourceId ?? item.label) === resolvedResource.name
                    ? truncateMiddle(
                        compactUsagePathIds(resolvedResource.name),
                        PATH_DISPLAY_MAX,
                      )
                    : resolvedResource.name
                  : undefined
              }
            />
          ) : computeResource ? (
            <BreakdownResourceRowLabel
              typeLabel={getComputeBreakdownResourceTypeLabel(
                computeResource.type,
              )}
              name={computeResource.name}
            />
          ) : storageResource ? (
            <BreakdownResourceRowLabel
              typeLabel={getStorageBreakdownResourceTypeLabel()}
              name={storageResource.name}
            />
          ) : tableResource ? (
            <BreakdownResourceRowLabel
              typeLabel={getTableBreakdownResourceTypeLabel(
                tableResource.databaseType,
              )}
              name={tableResource.name}
            />
          ) : databaseResource ? (
            <BreakdownResourceRowLabel
              typeLabel={getDatabaseBreakdownServiceLabel(
                databaseResource.databaseType,
              )}
              name={databaseResource.name}
            />
          ) : showMethodBadges ? (
            <Badge
              variant={getHttpMethodBadgeVariant(item.label)}
              className={cn(FORM_FIELD_TYPE_PILL_CLASS, 'uppercase')}
              title={displayLabel}
            >
              {formatHttpMethodBadgeLabel(displayLabel)}
            </Badge>
          ) : showStatusBadges ? (
            <Badge
              variant={getHttpStatusCodeBadgeVariant(item.label)}
              className={FORM_FIELD_TYPE_PILL_CLASS}
              title={displayLabel}
            >
              {displayLabel}
            </Badge>
          ) : (
            <span
              className={cn(
                'min-w-0 flex-1 truncate text-[12px] text-foreground/70',
                labelVariant === 'mono' &&
                  dimension !== 'country' &&
                  dimension !== 'hostname' &&
                  dimension !== 'service' &&
                  dimension !== 'resourceType' &&
                  'font-mono',
              )}
              title={displayLabel}
            >
              {displayLabel}
            </span>
          )}
        </div>
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
  const t = useT()
  return (
    <div
      className={overviewTopBreakdownListClass}
      aria-busy="true"
      aria-label={t('Loading breakdown')}
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
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
  variant?: 'card' | 'drawer'
  className?: string
  formatValue?: (value: number) => string
  onAddFilter?: (filters: UsageBreakdownFilterEntry[]) => void
}

export function UsageBreakdownRowsList({
  items,
  dimension,
  labelVariant,
  countryLookups,
  databaseLookup,
  computeLookup,
  storageLookup,
  tableLookup,
  variant = 'card',
  className,
  formatValue,
  onAddFilter,
}: UsageBreakdownRowsListProps) {
  const usageFilters = useOptionalUsageFilters()
  const handleAddFilter =
    onAddFilter ?? usageFilters?.onAddBreakdownFilter
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
              className={overviewTopBreakdownRowClass}
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
            storageLookup={storageLookup}
            tableLookup={tableLookup}
            maxCount={maxCount}
            formatValue={formatValue}
            onAddFilter={handleAddFilter}
          />
        )
      })}
    </div>
  )
}
