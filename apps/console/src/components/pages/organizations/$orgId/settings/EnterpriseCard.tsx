import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { EnterpriseBadge } from '@/components/global/shared/EnterpriseBadge'
import { useT } from '@/lib/i18n/translate'

/**
 * Enterprise card under the organization settings nav, for Pro organizations.
 */
export function EnterpriseCard({ orgId }: { orgId?: string }) {
  const t = useT()
  return (
    <div className="mt-4 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <EnterpriseBadge />
      <p className="mt-2 text-[12px] font-medium text-foreground">
        {t('Running Appwrite at scale?')}
      </p>
      <p className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
        {t(
          'Get a custom SLA, a private Slack channel, and SOC 2 and HIPAA without add-ons.',
        )}{' '}
        <ContactSalesLink
          source="settings"
          orgId={orgId}
          className="font-medium text-foreground underline hover:no-underline"
        >
          {t('Contact sales')}
        </ContactSalesLink>
      </p>
    </div>
  )
}
