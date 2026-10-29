import { CopyableId } from '@/components/global/shared/CopyableId'
import { useT } from '@/lib/i18n/translate'

export function OrganizationIdSettingsCard({
  organizationId,
}: {
  organizationId: string
}) {
  const t = useT()

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Organization ID')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="mb-3 text-[13px] text-muted-foreground">
          {t(
            'Use this ID when integrating with the Appwrite API, webhooks, or SDKs. Support may also ask for this ID when assisting with issues.', // pragma: allowlist secret
          )}
        </p>
        <CopyableId id={organizationId} size="md" maxWidth={240} />
      </div>
    </div>
  )
}
