import type { ReactNode } from 'react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'

/** Added · Status · Last check, above the records. */
export function DnsCheckMeta({
  createdAt,
  status,
  checkedAt,
  regionCount,
}: {
  createdAt: string
  /** The status of what the records verify, left out when it has none to show. */
  status?: ReactNode
  checkedAt: string
  /** How many regions checked DNS, 0 when the server does not say. */
  regionCount: number
}) {
  const t = useT()
  const label = 'text-[12px] text-muted-foreground'
  const value = 'mt-0.5 flex h-6 items-center text-[13px] text-foreground'
  return (
    <dl className="grid grid-cols-3 gap-4">
      <div className="min-w-0">
        <dt className={label}>{t('Added')}</dt>
        <dd className={value}>
          <DateTooltip date={createdAt} live />
        </dd>
      </div>
      {status && (
        <div className="min-w-0">
          <dt className={label}>{t('Status')}</dt>
          <dd className={value}>{status}</dd>
        </div>
      )}
      <div className="min-w-0">
        <dt className={label}>{t('Last check')}</dt>
        <dd className={value}>
          <DateTooltip date={checkedAt} live />
        </dd>
        {regionCount > 1 && (
          <dd className="mt-0.5 text-[12px] text-muted-foreground">
            {t('Checked from {n} regions').replace('{n}', String(regionCount))}
          </dd>
        )}
      </div>
    </dl>
  )
}
