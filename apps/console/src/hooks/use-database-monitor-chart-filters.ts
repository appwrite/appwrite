import { useCallback, useMemo, useState } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  DEFAULT_MONITOR_CHART_INTERVAL,
  getUsageChartIntervalsForPlan,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
  type UsageChartIntervalPlan,
} from '@/lib/usage/chart-interval'
import {
  getStableMonitorChartDateRange,
  normalizeUsageDateRangeSelection,
} from '@/lib/usage/usage-date-range'
import {
  findMatchingUsageDateRangePreset,
  getUsageDateRangePresetByValue,
} from '@/lib/usage/usage-date-range-presets'

const DEFAULT_MONITOR_PRESET_ID = '1h'

export type DatabaseMonitorChartFilters = {
  dateRange: DateRange
  chartInterval: UsageChartInterval
  dateRangePresetId: string | null
  planChartIntervals: UsageChartInterval[]
  setDateRange: (dateRange: DateRange | undefined) => void
  setChartInterval: (interval: UsageChartInterval) => void
  refreshRollingDateRange: () => void
  reset: () => void
}

export function useDatabaseMonitorChartFilters(
  plan?: UsageChartIntervalPlan,
): DatabaseMonitorChartFilters {
  const [rollingRangeNonce, setRollingRangeNonce] = useState(0)
  const [dateRangePresetId, setDateRangePresetId] = useState<string | null>(
    DEFAULT_MONITOR_PRESET_ID,
  )
  const [customDateRange, setCustomDateRange] = useState<DateRange | null>(null)
  const [chartInterval, setChartIntervalState] = useState<UsageChartInterval>(
    DEFAULT_MONITOR_CHART_INTERVAL,
  )

  const planChartIntervals = useMemo(
    () => getUsageChartIntervalsForPlan(plan),
    [plan],
  )

  const dateRange = useMemo(() => {
    if (dateRangePresetId) {
      const preset = getUsageDateRangePresetByValue(dateRangePresetId)
      if (preset) return preset.getRange()
    }
    if (customDateRange?.from) {
      return {
        from: customDateRange.from,
        to: customDateRange.to ?? customDateRange.from,
      }
    }
    return getStableMonitorChartDateRange()
  }, [customDateRange, dateRangePresetId, rollingRangeNonce])

  const resolvedChartInterval = useMemo(
    () => resolveUsageChartIntervalForRange(chartInterval, dateRange, plan),
    [chartInterval, dateRange, plan],
  )

  const refreshRollingDateRange = useCallback(() => {
    setRollingRangeNonce((nonce) => nonce + 1)
  }, [])

  const reset = useCallback(() => {
    setDateRangePresetId(DEFAULT_MONITOR_PRESET_ID)
    setCustomDateRange(null)
    setChartIntervalState(DEFAULT_MONITOR_CHART_INTERVAL)
    setRollingRangeNonce(0)
  }, [])

  const setDateRange = useCallback(
    (nextDateRange: DateRange | undefined) => {
      const normalizedSelection = normalizeUsageDateRangeSelection(nextDateRange)
      if (!normalizedSelection?.from) {
        setDateRangePresetId(DEFAULT_MONITOR_PRESET_ID)
        setCustomDateRange(null)
        setChartIntervalState((current) =>
          resolveUsageChartIntervalForRange(
            current,
            getStableMonitorChartDateRange(),
            plan,
          ),
        )
        return
      }

      const resolvedDateRange = {
        from: normalizedSelection.from,
        to: normalizedSelection.to ?? normalizedSelection.from,
      }

      const matchingPreset =
        findMatchingUsageDateRangePreset(resolvedDateRange)
      if (matchingPreset) {
        setDateRangePresetId(matchingPreset.value)
        setCustomDateRange(null)
      } else {
        setDateRangePresetId(null)
        setCustomDateRange(resolvedDateRange)
      }

      setChartIntervalState((current) =>
        resolveUsageChartIntervalForRange(current, resolvedDateRange, plan),
      )
    },
    [plan],
  )

  const setChartInterval = useCallback(
    (interval: UsageChartInterval) => {
      setChartIntervalState((current) => {
        const resolved = resolveUsageChartIntervalForRange(
          interval,
          dateRange,
          plan,
        )
        return resolved === current ? current : resolved
      })
    },
    [dateRange, plan],
  )

  return {
    dateRange,
    chartInterval: resolvedChartInterval,
    dateRangePresetId,
    planChartIntervals,
    setDateRange,
    setChartInterval,
    refreshRollingDateRange,
    reset,
  }
}
