import { AlertCircle, Loader2 } from 'lucide-react'
import { HeaderAlertBar } from '@/components/global/shared/HeaderAlertBar'
import {
  coerceTrimmedString,
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

  const normalized = coerceTrimmedString(status)
  if (!normalized || isDedicatedDatabaseReady(normalized)) {
    return null
  }

  const normalizedStatus = normalized.toLowerCase()
  const variant = dedicatedDatabaseHeaderAlertVariant(normalized)
  const titleKey = dedicatedDatabaseStatusAlertTitleKey(normalized)
  const descriptionKey = dedicatedDatabaseStatusAlertDescriptionKey(normalized)
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
