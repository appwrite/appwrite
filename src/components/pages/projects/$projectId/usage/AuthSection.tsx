'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  AUTH_DOCS_HREF,
  AUTH_MAU_DESCRIPTION,
  AUTH_OTP_DESCRIPTION,
  AUTH_SIGNUPS_DESCRIPTION,
  formatAuthCountAxisValue,
  formatAuthMauTotal,
  formatAuthMauValue,
  formatAuthOtpTotal,
  formatAuthOtpValue,
  formatAuthSignupsTotal,
  formatAuthSignupsValue,
  getAuthMauDisplayTotal,
  getAuthSignupsDisplayTotal,
  sumUsageChartPoints,
} from '@/lib/usage/auth-usage'
import {
  refetchProjectAuthUsageQueries,
  useProjectAuthMauChart,
  useProjectAuthOtpChart,
  useProjectAuthSignupsChart,
} from '@/lib/react-query/hooks'
import { useRefresh } from '@/components/global/shared/RefreshContext'
import { UsageTimeSeriesChartCard } from './_components/UsageTimeSeriesChartCard'

const AUTH_USAGE_ERROR = {
  title: "Couldn't load auth usage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type AuthSectionProps = {
  projectId: string
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
}

export function AuthSection({
  projectId,
  dateRange,
  chartInterval,
}: AuthSectionProps) {
  const queryClient = useQueryClient()
  const { registerRefreshHandler, unregisterRefreshHandler } = useRefresh()

  const mauQuery = useProjectAuthMauChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const otpQuery = useProjectAuthOtpChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )
  const signupsQuery = useProjectAuthSignupsChart(
    projectId,
    dateRange,
    true,
    chartInterval,
  )

  useEffect(() => {
    registerRefreshHandler(
      () => refetchProjectAuthUsageQueries(queryClient, projectId),
      'Usage data',
    )
    return () => unregisterRefreshHandler()
  }, [
    queryClient,
    projectId,
    registerRefreshHandler,
    unregisterRefreshHandler,
  ])

  const handleRetryAll = () => {
    void refetchProjectAuthUsageQueries(queryClient, projectId)
  }

  const mauPoints = mauQuery.isError ? [] : (mauQuery.data?.chartPoints ?? [])
  const otpPoints = otpQuery.isError ? [] : (otpQuery.data?.chartPoints ?? [])
  const signupsPoints = signupsQuery.isError
    ? []
    : (signupsQuery.data?.chartPoints ?? [])

  return (
    <div className="space-y-6">
      <UsageTimeSeriesChartCard
        title="Monthly active users"
        description={AUTH_MAU_DESCRIPTION}
        unitLabel="users"
        chartGradientId="usage-auth-mau-gradient"
        total={getAuthMauDisplayTotal(mauPoints)}
        changePercent={mauQuery.data?.changePercent ?? 0}
        chartPoints={mauPoints}
        isLoading={
          mauQuery.isLoading && !mauQuery.isError && !mauQuery.data
        }
        isError={mauQuery.isError}
        errorTitle={AUTH_USAGE_ERROR.title}
        errorMessage={AUTH_USAGE_ERROR.message}
        formatTotal={formatAuthMauTotal}
        formatValue={formatAuthMauValue}
        formatAxisValue={formatAuthCountAxisValue}
        onRetry={handleRetryAll}
        docsHref={AUTH_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="OTP attempts"
        description={AUTH_OTP_DESCRIPTION}
        unitLabel="attempts"
        chartGradientId="usage-auth-otp-gradient"
        total={sumUsageChartPoints(otpPoints)}
        changePercent={otpQuery.data?.changePercent ?? 0}
        chartPoints={otpPoints}
        isLoading={
          otpQuery.isLoading && !otpQuery.isError && !otpQuery.data
        }
        isError={otpQuery.isError}
        errorTitle={AUTH_USAGE_ERROR.title}
        errorMessage={AUTH_USAGE_ERROR.message}
        formatTotal={formatAuthOtpTotal}
        formatValue={formatAuthOtpValue}
        formatAxisValue={formatAuthCountAxisValue}
        onRetry={handleRetryAll}
        docsHref={AUTH_DOCS_HREF}
      />

      <UsageTimeSeriesChartCard
        title="Sign-ups"
        description={AUTH_SIGNUPS_DESCRIPTION}
        unitLabel="users"
        chartGradientId="usage-auth-signups-gradient"
        total={getAuthSignupsDisplayTotal(signupsPoints)}
        changePercent={signupsQuery.data?.changePercent ?? 0}
        chartPoints={signupsPoints}
        isLoading={
          signupsQuery.isLoading && !signupsQuery.isError && !signupsQuery.data
        }
        isError={signupsQuery.isError}
        errorTitle={AUTH_USAGE_ERROR.title}
        errorMessage={AUTH_USAGE_ERROR.message}
        formatTotal={formatAuthSignupsTotal}
        formatValue={formatAuthSignupsValue}
        formatAxisValue={formatAuthCountAxisValue}
        onRetry={handleRetryAll}
        docsHref={AUTH_DOCS_HREF}
      />
    </div>
  )
}
