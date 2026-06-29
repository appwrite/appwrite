import { createContext, useContext, type ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'

export type UsageFiltersContextValue = {
  plan: 'free' | 'pro' | 'custom'
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

const UsageFiltersContext = createContext<UsageFiltersContextValue | null>(null)

export function UsageFiltersProvider({
  value,
  children,
}: {
  value: UsageFiltersContextValue
  children: ReactNode
}) {
  return (
    <UsageFiltersContext.Provider value={value}>
      {children}
    </UsageFiltersContext.Provider>
  )
}

export function useUsageFilters(): UsageFiltersContextValue {
  const context = useContext(UsageFiltersContext)
  if (!context) {
    throw new Error('useUsageFilters must be used within UsageFiltersProvider')
  }
  return context
}

export function useOptionalUsageFilters(): UsageFiltersContextValue | null {
  return useContext(UsageFiltersContext)
}
