import { useT } from '@/lib/i18n/translate'
import type { UsageChartErrorCopy } from '@/lib/usage/usage-history-errors'

export function UsageChartErrorMessage({
  copy,
}: {
  copy: Pick<
    UsageChartErrorCopy,
    'message' | 'isRetentionLimit' | 'isAddonNotFound' | 'retentionDays'
  >
}) {
  const t = useT()

  if (copy.isAddonNotFound) {
    return <>{t(copy.message)}</>
  }

  if (copy.isRetentionLimit && copy.retentionDays != null) {
    return (
      <>
        {t('Your plan includes')}{' '}
        <span className="font-medium">{copy.retentionDays}</span>{' '}
        {t(
          'days of usage history. Choose a shorter date range or upgrade for longer retention.',
        )}
      </>
    )
  }

  return <>{t(copy.message)}</>
}
