import { Link } from '@tanstack/react-router'
import { Webhook, Zap } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  canCreateFunction,
  canWriteWebhooks,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'
import { ALERT_TEMPLATES_USE_CASE } from '@/lib/react-query/hooks/functions'
import { useT } from '@/lib/i18n/translate'

/** The project events every alert fires, which a webhook or function subscribes to. */
export const ALERT_EVENTS = [
  'monitors.*.alerts.*.create',
  'monitors.*.alerts.*.update',
]

/**
 * Where alerts go besides the members a monitor notifies, with quick actions that
 * open a new project webhook already subscribed to the alert events, or the
 * function templates that act on them.
 */
export function AlertDelivery({ projectId }: { projectId: string }) {
  const t = useT()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  const webhooks = canWriteWebhooks(access, features)
  const functions = canCreateFunction(access, features)
  if (!webhooks && !functions) return null

  return (
    <div className="mt-6 flex flex-col gap-3 rounded-lg border border-border bg-card/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-[13px] text-muted-foreground">
        {t(
          'Each monitor emails and notifies the organization roles it names. Every alert is also a project event your own systems can receive.',
        )}
      </p>
      <div className="flex shrink-0 flex-wrap gap-2">
        {functions && (
          <Button
            asChild
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
          >
            <Link
              to="/projects/$projectId/functions/templates"
              params={{ projectId }}
              search={{ uc: ALERT_TEMPLATES_USE_CASE }}
            >
              <Zap className="me-1.5 h-3.5 w-3.5" />
              {t('Deploy an alert function')}
            </Link>
          </Button>
        )}
        {webhooks && (
          <Button
            asChild
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
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
        )}
      </div>
    </div>
  )
}
