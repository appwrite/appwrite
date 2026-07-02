'use client'

import { useState, type ReactNode } from 'react'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { cn } from '@/lib/utils'
import type { CountryLookups } from '@/lib/locale/country-lookups'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import type { UsageResourceBreakdownDimension } from '@/lib/usage/usage-resources-breakdown'
import {
  USAGE_RESOURCE_BREAKDOWN_VIEWS,
  USAGE_RESOURCES_BREAKDOWN_TITLE,
} from '@/lib/usage/usage-resources-breakdown'
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

const TOGGLE_ITEM_CLASS =
  'h-7 flex-1 min-w-0 truncate px-2 text-[11px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground'

type UsageResourceBreakdownViewState = {
  items: UsageBreakdownItem[]
  isLoading: boolean
  isError: boolean
}

type UsageResourceBreakdownCardProps = {
  title?: string
  description: string
  resourceIdView: UsageResourceBreakdownViewState
  resourceTypeView: UsageResourceBreakdownViewState
  countryLookups?: CountryLookups | null
  databaseLookup?: DatabaseBreakdownResourceMap | null
  computeLookup?: ComputeBreakdownResourceMap | null
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
  errorTitle: string
  errorMessage: string
  formatValue: (value: number) => string
  onRetry?: () => void
  onShowMore?: (dimension: UsageResourceBreakdownDimension) => void
  titleAddon?: ReactNode
  embedded?: boolean
  className?: string
}

function UsageResourceBreakdownDimensionToggle({
  value,
  onValueChange,
  className,
}: {
  value: UsageResourceBreakdownDimension
  onValueChange: (value: UsageResourceBreakdownDimension) => void
  className?: string
}) {
  return (
    <ToggleGroup
      type="single"
      variant="outline"
      size="sm"
      value={value}
      onValueChange={(next) => {
        if (next === 'resourceId' || next === 'resource') {
          onValueChange(next)
        }
      }}
      className={cn('flex h-7 w-full min-w-0 gap-0 rounded-md', className)}
      aria-label="Resource breakdown dimension"
    >
      {USAGE_RESOURCE_BREAKDOWN_VIEWS.map((view, index) => {
        const isFirst = index === 0
        const isLast = index === USAGE_RESOURCE_BREAKDOWN_VIEWS.length - 1

        return (
          <ToggleGroupItem
            key={view.dimension}
            value={view.dimension}
            className={cn(
              TOGGLE_ITEM_CLASS,
              '!rounded-none shadow-none',
              isFirst && '!rounded-s-md !border-s',
              isLast && '!rounded-e-md',
              !isFirst && '!border-s-0',
            )}
          >
            {view.label}
          </ToggleGroupItem>
        )
      })}
    </ToggleGroup>
  )
}

export function UsageResourceBreakdownCard({
  title = USAGE_RESOURCES_BREAKDOWN_TITLE,
  description,
  resourceIdView,
  resourceTypeView,
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
  titleAddon,
  embedded = false,
  className,
}: UsageResourceBreakdownCardProps) {
  const [activeDimension, setActiveDimension] =
    useState<UsageResourceBreakdownDimension>('resourceId')

  const activeView =
    USAGE_RESOURCE_BREAKDOWN_VIEWS.find(
      (view) => view.dimension === activeDimension,
    ) ?? USAGE_RESOURCE_BREAKDOWN_VIEWS[0]
  const activeState =
    activeDimension === 'resourceId' ? resourceIdView : resourceTypeView
  const { items, isLoading, isError } = activeState

  const showDatabaseIcons =
    activeDimension === 'resourceId' &&
    !!(databaseLookup || computeLookup || storageLookup || tableLookup) &&
    items.length > 0
  const showLeadingIcon = showDatabaseIcons
  const showEmptyOverlay = !isLoading && !isError && items.length === 0
  const showShowMore =
    !isError &&
    items.length >= OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT &&
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
              {title}
            </h3>
            {titleAddon}
          </div>
          {showShowMore && onShowMore ? (
            <button
              type="button"
              className="shrink-0 cursor-pointer text-[12px] text-muted-foreground transition-colors hover:text-foreground"
              onClick={() => onShowMore(activeDimension)}
            >
              Show more
            </button>
          ) : null}
        </div>
        <UsageResourceBreakdownDimensionToggle
          value={activeDimension}
          onValueChange={setActiveDimension}
        />
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
              dimension={activeView.dimension}
              labelVariant={activeView.labelVariant}
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
    <UsageMetricCardShell className={className}>
      {breakdownContent}
      <UsageMetricCardFooter description={description} />
    </UsageMetricCardShell>
  )
}
