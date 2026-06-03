import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'

const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL ||
  'https://appwrite.io/contact-us/enterprise'

type SpecificationsUpgradeNoteProps = {
  orgId?: string | null
  /** When true, appends "or contact sales" before the unlock suffix. */
  showContactSales?: boolean
  analyticsSurface?: string
}

/**
 * Footer note shown when the plan locks higher CPU/memory specifications.
 */
export function SpecificationsUpgradeNote({
  orgId,
  showContactSales = false,
  analyticsSurface,
}: SpecificationsUpgradeNoteProps) {
  return (
    <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        Need more resources?{' '}
        <UpgradePlanLink
          orgId={orgId}
          analyticsSurface={analyticsSurface}
          data-analytics-resource="specification"
        />{' '}
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
