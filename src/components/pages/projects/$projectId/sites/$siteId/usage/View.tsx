import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { UsageChartIntervalToggle } from '@/components/pages/projects/$projectId/overview/UsageChartIntervalToggle'
import { ComputeUsageSection } from '@/components/pages/projects/$projectId/usage/_components/ComputeUsageSection'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'
import { useOrganizationPlan, useProject } from '@/lib/react-query/hooks'
import { getUsageChartIntervalsForPlan } from '@/lib/usage/chart-interval'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const allowedIntervals = useMemo(
    () => getUsageChartIntervalsForPlan(plan),
    [plan],
  )
  const {
    dateRange,
    chartInterval,
    dateRangePresetId,
    setDateRange,
    setChartInterval,
  } = useUsageChartFilters(plan)

  if (!projectId || !siteId) return null

  return (
    <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
      <div className="flex flex-col gap-4 py-6 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 className="text-lg font-semibold">{t('Usage')}</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {t('View usage statistics for this site')}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <DateRangePicker
            dateRange={dateRange}
            onDateRangeChange={setDateRange}
            presetId={dateRangePresetId}
          />
          <UsageChartIntervalToggle
            value={chartInterval}
            onValueChange={setChartInterval}
            dateRange={dateRange}
            allowedIntervals={allowedIntervals}
          />
        </div>
      </div>

      <ComputeUsageSection
        projectId={projectId}
        siteId={siteId}
        scope="sites"
        dateRange={dateRange}
        chartInterval={chartInterval}
        onDateRangeChange={setDateRange}
      />
    </div>
  )
}
