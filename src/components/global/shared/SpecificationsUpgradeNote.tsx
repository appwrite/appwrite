import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useT } from '@/lib/i18n/translate'

type SpecificationsUpgradeNoteProps = {
  orgId?: string | null
  /** When true, appends "or contact sales" before the unlock suffix. */
  showContactSales?: boolean
}

/**
 * Footer note shown when the plan locks higher CPU/memory specifications.
 */
export function SpecificationsUpgradeNote({
  orgId,
  showContactSales = false,
}: SpecificationsUpgradeNoteProps) {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        {t('Need more resources?')} <UpgradePlanLink orgId={orgId} />{' '}
        {showContactSales ? (
          <>
            {t('or')}{' '}
            <ContactSalesLink className="font-medium text-foreground underline hover:no-underline">
              {t('contact sales')}
            </ContactSalesLink>{' '}
          </>
        ) : null}
        {t('to unlock additional specifications.')}
      </p>
    </div>
  )
}
