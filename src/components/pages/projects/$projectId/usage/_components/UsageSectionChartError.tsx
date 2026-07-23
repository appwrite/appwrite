'use client'

import { useMemo } from 'react'
import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import {
  isUsageAddonNotFoundError,
  resolveUsageChartErrorCopy,
  shouldSuppressUsageChartRetry,
} from '@/lib/usage/usage-history-errors'
import { DEFAULT_USAGE_LOG_RETENTION_DAYS } from '@/lib/usage/usage-log-retention'
import { UsageChartErrorMessage } from '../../shared/UsageChartErrorMessage'
import { useOptionalUsageFilters } from '../usage-filters-context'
import { UsagePremiumGeoDBCurtain } from './UsagePremiumGeoDBCurtain'

type UsageSectionChartErrorProps = {
  error?: unknown
  errorTitle: string
  errorMessage: string
  onRetry?: () => void
}

export function UsageSectionChartError({
  error,
  errorTitle,
  errorMessage,
  onRetry,
}: UsageSectionChartErrorProps) {
  const t = useT()
  const usageFilters = useOptionalUsageFilters()
  const resolvedErrorCopy = useMemo(
    () =>
      resolveUsageChartErrorCopy(
        error,
        usageFilters?.usageLogRetentionDays ?? DEFAULT_USAGE_LOG_RETENTION_DAYS,
        { title: errorTitle, message: errorMessage },
      ),
    [error, usageFilters?.usageLogRetentionDays, errorTitle, errorMessage],
  )
  const showRetry =
    !!onRetry && !shouldSuppressUsageChartRetry(resolvedErrorCopy)

  if (isUsageAddonNotFoundError(error) || resolvedErrorCopy.isAddonNotFound) {
    return (
      <UsagePremiumGeoDBCurtain
        className="absolute inset-0 rounded-lg"
        onEnabled={onRetry}
      >
        <div className="h-full min-h-[200px] rounded-lg border border-dashed border-border bg-muted/20" />
      </UsagePremiumGeoDBCurtain>
    )
  }

  return (
    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center">
      <AlertCircle className="h-8 w-8 shrink-0 text-muted-foreground" />
      <div className="max-w-sm">
        <p className="text-[13px] font-medium text-foreground">
          {t(resolvedErrorCopy.title)}
        </p>
        <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
          <UsageChartErrorMessage copy={resolvedErrorCopy} />
        </p>
      </div>
      {showRetry ? (
        <Button variant="outline" size="sm" onClick={onRetry}>
          {t('Try again')}
        </Button>
      ) : null}
    </div>
  )
}
