import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { EnterpriseBadge } from '@/components/global/shared/EnterpriseBadge'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { isSelfServiceProPlan } from '@/lib/utils/plan-filter'
import { useT } from '@/lib/i18n/translate'

type SpecificationsUpgradeNoteProps = {
  orgId?: string | null
}

/**
 * Footer note shown when the plan locks higher CPU/memory specifications.
 * No self-serve plan sits above Pro, so Pro is pointed at sales instead.
 */
export function SpecificationsUpgradeNote({
  orgId,
}: SpecificationsUpgradeNoteProps) {
  const t = useT()
  const { plan } = useOrganizationPlan(orgId ?? undefined)
  const showContactSales = isSelfServiceProPlan(plan)

  return (
    <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        {showContactSales && <EnterpriseBadge />}
        {t('Need more resources?')}{' '}
        {showContactSales ? (
          <>
            {t('Higher CPU and memory are available on Enterprise.')}{' '}
            <ContactSalesLink
              source="specs"
              orgId={orgId}
              className="font-medium text-foreground underline hover:no-underline"
            >
              {t('Contact sales')}
            </ContactSalesLink>
          </>
        ) : (
          <>
            <UpgradePlanLink orgId={orgId} />{' '}
            {t('to unlock additional specifications.')}
          </>
        )}
      </p>
    </div>
  )
}
