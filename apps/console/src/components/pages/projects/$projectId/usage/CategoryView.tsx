import type { DateRange } from 'react-day-picker'
import { UsageMetricChart } from './MetricChart'
import { RequestsSection } from './RequestsSection'
import { BandwidthSection } from './BandwidthSection'
import { DatabasesSection } from './DatabasesSection'
import { RealtimeSection } from './RealtimeSection'
import { AuthSection } from './AuthSection'
import { AvatarsSection } from './AvatarsSection'
import { MessagingSection } from './MessagingSection'
import { WebhooksSection } from './WebhooksSection'
import { ComputeSection } from './ComputeSection'
import { FunctionsSection } from './FunctionsSection'
import { SitesSection } from './SitesSection'
import { StorageSection } from './StorageSection'
import { findUsageCategory, getUsageCategories } from './usage-nav'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'

type UsageCategoryViewProps = {
  projectId: string
  categoryId: string
  plan?: 'free' | 'pro' | 'custom'
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function UsageCategoryView({
  projectId,
  categoryId,
  plan = 'pro',
  dateRange,
  chartInterval,
}: UsageCategoryViewProps) {
  const category = findUsageCategory(getUsageCategories(plan), categoryId)

  if (!category) {
    return null
  }

  if (categoryId === 'requests') {
    return (
      <RequestsSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'bandwidth') {
    return (
      <BandwidthSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'compute') {
    return (
      <ComputeSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'functions') {
    return (
      <FunctionsSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'sites') {
    return (
      <SitesSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'databases') {
    return (
      <DatabasesSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'realtime') {
    return (
      <RealtimeSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'auth') {
    return (
      <AuthSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'avatars') {
    return (
      <AvatarsSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'messaging') {
    return (
      <MessagingSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'webhooks') {
    return (
      <WebhooksSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  if (categoryId === 'storage') {
    return (
      <StorageSection
        projectId={projectId}
        dateRange={dateRange}
        chartInterval={chartInterval}
      />
    )
  }

  return (
    <div className="space-y-6">
      {category.metrics.map((metric) => (
        <UsageMetricChart key={metric.id} metric={metric} />
      ))}
    </div>
  )
}
