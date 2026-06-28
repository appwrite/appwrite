'use client'

import { useEffect, useRef } from 'react'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import { DateRangePicker } from '../analytics/DateRangePicker'
import { UsageHistoricDataNote } from '../shared/UsageHistoricDataNote'
import { UsageChartIntervalToggle } from '../overview/UsageChartIntervalToggle'
import { ComputeUsageSection, type ComputeUsageScope } from '../usage/_components/ComputeUsageSection'
import {
  ServiceHeader,
  type Tab,
} from './ServiceHeader'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'

type ProductComputeUsageViewProps = {
  projectId: string
  title: string
  tabs: Tab[]
  activeTab: string
  scope: Extract<ComputeUsageScope, 'functions' | 'sites'>
}

function ProductComputeUsageContent({
  projectId,
  title,
  tabs,
  activeTab,
  scope,
}: ProductComputeUsageViewProps) {
  const contentScrollRef = useRef<HTMLDivElement>(null)
  const {
    dateRange,
    chartInterval,
    setDateRange,
    setChartInterval,
    refreshRollingDateRange,
  } = useUsageChartFilters()
  const { triggerRefresh, hasRefreshHandler, isRefreshing } = useRefresh()

  useEffect(() => {
    contentScrollRef.current?.scrollTo({ top: 0 })
  }, [scope])

  const handleRefresh = () => {
    refreshRollingDateRange()
    if (hasRefreshHandler) {
      void triggerRefresh()
    }
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <ServiceHeader
        title={title}
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        showRefresh
        onRefresh={handleRefresh}
        isRefreshing={isRefreshing}
        beforeRefreshButtons={
          <UsageChartIntervalToggle
            dateRange={dateRange}
            value={chartInterval}
            onChange={setChartInterval}
          />
        }
      />

      <div
        ref={contentScrollRef}
        className="flex-1 min-h-0 overflow-y-auto"
      >
        <div className="mx-auto w-full max-w-7xl px-4 pb-4 pt-4 sm:px-6 sm:pb-6">
          <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <UsageHistoricDataNote />
            <DateRangePicker
              dateRange={dateRange}
              onDateRangeChange={setDateRange}
            />
          </div>

          <ComputeUsageSection
            projectId={projectId}
            dateRange={dateRange}
            chartInterval={chartInterval}
            scope={scope}
          />
        </div>
      </div>
    </div>
  )
}

export function ProductComputeUsageView(props: ProductComputeUsageViewProps) {
  return (
    <RefreshProvider>
      <ProductComputeUsageContent {...props} />
    </RefreshProvider>
  )
}

export function getFunctionsServiceTabs(projectId: string): Tab[] {
  return [
    {
      id: 'functions',
      label: 'Functions',
      to: '/projects/$projectId/functions/',
      params: { projectId },
    },
    {
      id: 'templates',
      label: 'Templates',
      to: '/projects/$projectId/functions/templates',
      params: { projectId },
    },
    {
      id: 'usage',
      label: 'Usage',
      to: '/projects/$projectId/functions/usage',
      params: { projectId },
    },
  ]
}

export function getSitesServiceTabs(projectId: string): Tab[] {
  return [
    {
      id: 'sites',
      label: 'Sites',
      to: '/projects/$projectId/sites',
      params: { projectId },
    },
    {
      id: 'usage',
      label: 'Usage',
      to: '/projects/$projectId/sites/usage',
      params: { projectId },
    },
  ]
}
