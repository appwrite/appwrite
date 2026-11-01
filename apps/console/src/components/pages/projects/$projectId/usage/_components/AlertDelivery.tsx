import { Link } from '@tanstack/react-router'
import { Webhook } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { canWriteWebhooks } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

/** The project events every alert fires, which a webhook or function subscribes to. */
export const ALERT_EVENTS = [
  'monitors.*.alerts.*.firing',
  'monitors.*.alerts.*.resolved',
]

/**
 * Where alerts go besides the members a monitor notifies, with a quick action that
 * opens a new project webhook already subscribed to the alert events.
 */
export function AlertDelivery({ projectId }: { projectId: string }) {
  const t = useT()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  if (!canWriteWebhooks(access, features)) return null

  return (
    <div className="mt-6 flex flex-col gap-3 rounded-lg border border-border bg-card/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-[13px] text-muted-foreground">
        {t(
          'Each monitor emails and notifies the organization roles it names. Every alert is also a project event your own systems can receive.',
        )}
      </p>
      <Button
        asChild
        variant="outline"
        size="sm"
        className="h-8 shrink-0 text-[12px]"
      >
        <Link
          to="/projects/$projectId/settings/webhooks"
          params={{ projectId }}
          search={{ events: ALERT_EVENTS, name: t('Alerts') }}
        >
          <Webhook className="me-1.5 h-3.5 w-3.5" />
          {t('Send alerts to a webhook')}
        </Link>
      </Button>
    </div>
  )
}
