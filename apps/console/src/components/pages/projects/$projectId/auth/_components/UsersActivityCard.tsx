import { useMemo } from 'react'
import {
  useProjectAuthMauChart,
  useProjectAuthSignupsChart,
} from '@/lib/react-query/hooks'
import {
  formatAuthMauTotal,
  formatAuthMauValue,
  formatAuthSignupsTotal,
  formatAuthSignupsValue,
  getAuthMauDisplayTotal,
  getAuthSignupsDisplayTotal,
} from '@/lib/usage/auth-usage'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import type { UsageChartPoint } from '@/lib/usage/usage-events-common'
import { useT } from '@/lib/i18n/translate'
import { DualUsageCard } from '../../shared/ResourceUsageCards'

const toTrend = (points: UsageChartPoint[]) =>
  points.map((point) => ({ label: point.date, value: point.total }))

const isInitial = (query: {
  isPending: boolean
  data?: unknown
  isError: boolean
}) => query.isPending && !query.data && !query.isError

export function UsersActivityCard({ projectId }: { projectId: string }) {
  const t = useT()
  const dateRange = useMemo(() => getStableUsageChartDateRange(), [])
  const mauQuery = useProjectAuthMauChart(projectId, dateRange)
  const signupsQuery = useProjectAuthSignupsChart(projectId, dateRange)

  const mauPoints = mauQuery.data?.chartPoints ?? []
  const signupsPoints = signupsQuery.data?.chartPoints ?? []
  const mau = getAuthMauDisplayTotal(mauPoints)
  const signups = getAuthSignupsDisplayTotal(signupsPoints)

  return (
    <DualUsageCard
      title={t('Activity')}
      subtitle={t('Last 24 hours')}
      action={{
        href: {
          to: '/projects/$projectId/usage/$categoryId',
          params: { projectId, categoryId: 'auth' },
        },
        label: t('View usage'),
      }}
      left={{
        title: t('Monthly active users'),
        value: formatAuthMauTotal(mau),
        change: mauQuery.data?.changePercent,
        loading: isInitial(mauQuery),
        hasActivity: mau > 0,
        trend: toTrend(mauPoints),
        color: 'var(--chart-brand)',
        formatValue: formatAuthMauValue,
        valueLabel: t('users'),
        emptyTitle: t('No active users yet'),
        errorTitle: mauQuery.isError
          ? t("Couldn't load active users")
          : undefined,
      }}
      right={{
        title: t('Sign-ups'),
        value: formatAuthSignupsTotal(signups),
        change: signupsQuery.data?.changePercent,
        loading: isInitial(signupsQuery),
        hasActivity: signups > 0,
        trend: toTrend(signupsPoints),
        color: 'var(--chart-2)',
        formatValue: formatAuthSignupsValue,
        valueLabel: t('sign-ups'),
        emptyTitle: t('No sign-ups yet'),
        errorTitle: signupsQuery.isError
          ? t("Couldn't load sign-ups")
          : undefined,
      }}
    />
  )
}
