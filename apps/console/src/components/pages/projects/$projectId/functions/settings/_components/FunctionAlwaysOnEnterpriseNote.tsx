import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { EnterpriseBadge } from '@/components/global/shared/EnterpriseBadge'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

/**
 * Enterprise note on function runtime specification settings (always-on + higher specs).
 */
export function FunctionRuntimeEnterpriseOfferingNote({
  orgId,
}: {
  orgId?: string | null
}) {
  const t = useT()
  return (
    <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        <EnterpriseBadge />
        {t(
          'Always-on functions and higher CPU and memory are available on Enterprise.',
        )}{' '}
        <ContactSalesLink
          source="specs"
          orgId={orgId}
          className="font-medium text-foreground underline hover:no-underline"
          {...analyticsAttrs('functions-always-on-contact-sales')}
        >
          {t('Contact sales')}
        </ContactSalesLink>
      </p>
    </div>
  )
}
