import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'

const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL || CONTACT_ENTERPRISE_URL

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
  return (
    <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        Need more resources? <UpgradePlanLink orgId={orgId} />{' '}
        {showContactSales ? (
          <>
            or{' '}
            <a
              href={CONTACT_SALES_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="font-medium text-foreground underline hover:no-underline"
            >
              contact sales
            </a>{' '}
          </>
        ) : null}
        to unlock additional specifications.
      </p>
    </div>
  )
}
