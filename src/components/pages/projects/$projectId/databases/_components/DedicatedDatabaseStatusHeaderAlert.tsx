import { AlertCircle, Loader2 } from 'lucide-react'
import { HeaderAlertBar } from '@/components/global/shared/HeaderAlertBar'
import {
  dedicatedDatabaseHeaderAlertVariant,
  dedicatedDatabaseStatusAlertDescriptionKey,
  dedicatedDatabaseStatusAlertTitleKey,
  isDedicatedDatabaseReady,
} from '@/lib/databases/dedicated-database-status'
import { localizeResourceStatusLabel } from '@/lib/i18n/resource-status-labels'
import { useT } from '@/lib/i18n/translate'

type DedicatedDatabaseStatusHeaderAlertProps = {
  status: string | null | undefined
}

export function DedicatedDatabaseStatusHeaderAlert({
  status,
}: DedicatedDatabaseStatusHeaderAlertProps) {
  const t = useT()

  if (!status?.trim() || isDedicatedDatabaseReady(status)) {
    return null
  }

  const normalizedStatus = status.trim().toLowerCase()
  const variant = dedicatedDatabaseHeaderAlertVariant(status)
  const titleKey = dedicatedDatabaseStatusAlertTitleKey(status)
  const descriptionKey = dedicatedDatabaseStatusAlertDescriptionKey(status)
  const showSpinner = !['failed', 'deleted', 'paused', 'inactive'].includes(
    normalizedStatus,
  )

  return (
    <HeaderAlertBar
      variant={variant}
      icon={showSpinner ? Loader2 : AlertCircle}
      className={showSpinner ? 'shrink-0 [&_svg]:animate-spin' : 'shrink-0'}
    >
      <p className="font-semibold">{t(titleKey)}</p>
      <p className="mt-1 font-normal text-[12px] opacity-90">
        {t(descriptionKey)}
      </p>
      {titleKey === 'Database is not ready' ? (
        <p className="mt-1 font-normal text-[12px] opacity-90">
          {t('Current status')}:{' '}
          <span className="font-medium">
            {localizeResourceStatusLabel(status, t)}
          </span>
        </p>
      ) : null}
    </HeaderAlertBar>
  )
}
