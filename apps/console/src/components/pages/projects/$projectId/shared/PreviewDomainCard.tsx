/**
 * Preview Domain Card Component
 *
 * Shows the shared domain deployment and branch previews are served from
 * (`_APP_DOMAIN_SITES`) and offers a custom preview domain through sales.
 */

import { Button } from '@/components/ui/button'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { useConsoleVariables, useProject } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface PreviewDomainCardProps {
  projectId: string | null | undefined
}

export function PreviewDomainCard({ projectId }: PreviewDomainCardProps) {
  const t = useT()
  const { project } = useProject(projectId ?? undefined)
  const { sitesDomain } = useConsoleVariables(project?.region)
  const previewDomain = sitesDomain || 'appwrite.network'

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Preview domain')}
          </h3>
          <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
            {t('Enterprise')}
          </span>
        </div>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Deployment and branch previews get a generated URL on a shared Appwrite domain.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-3">
        <p className="text-[13px] text-muted-foreground">
          {t('Current preview domain:')}{' '}
          <code className="ms-1.5 rounded bg-muted px-1.5 py-0.5 font-mono text-[12px] text-foreground">
            *.{previewDomain}
          </code>
        </p>
        <p className="text-[13px] text-muted-foreground">
          {t(
            'Serve previews from a domain you own so every preview link matches your brand. Custom preview domains are available on Enterprise plans.',
          )}
        </p>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
          <ContactSalesLink source="preview-domains" orgId={project?.teamId}>
            {t('Contact sales')}
          </ContactSalesLink>
        </Button>
      </div>
    </div>
  )
}
