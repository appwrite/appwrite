import { useMemo, useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { AgentMarks } from '@/components/global/shared/connect-agent-cta/primitives'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { canShowAgentMcpConnectCta } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  buildConnectMcpPrompt,
  hasAccountMcpAgentConnected,
} from '@/lib/mcp-adoption'
import {
  useAccountConnectedApps,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

export function ConnectAgentCta({
  projectId,
  className,
  initialConnected,
}: {
  projectId: string
  className?: string
  /** Prefetched MCP connection state from the route loader. */
  initialConnected?: boolean
}) {
  const t = useT()
  const { isAuthenticated } = useAuth()
  const { features, isSelfHosted } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { data: connectedApps, isFetched } = useAccountConnectedApps({
    enabled: isAuthenticated,
  })
  const projectConnect = useProjectConnectDialog()
  const [copied, setCopied] = useState(false)

  const canShow =
    isAuthenticated && canShowAgentMcpConnectCta(access, features)
  const connected = isFetched
    ? hasAccountMcpAgentConnected(connectedApps?.groups)
    : initialConnected !== false
  const projectName = project?.name ?? projectId
  const connectPrompt = useMemo(
    () =>
      buildConnectMcpPrompt({
        projectId,
        projectName,
        isSelfHosted,
        endpoint: getApiEndpoint(project?.region),
      }),
    [projectId, projectName, isSelfHosted, project?.region],
  )

  if (!canShow || !projectConnect || connected) {
    return null
  }

  const handleCopyPrompt = async () => {
    try {
      await navigator.clipboard.writeText(connectPrompt)
      toast.success(t('Prompt copied to clipboard'))
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy prompt'))
    }
  }

  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/50',
        className,
      )}
    >
      <div className="flex items-start gap-4 px-6 py-4">
        <AgentMarks className="mt-0.5 shrink-0" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Connect Appwrite with your agents')}
            </h3>
            <Badge
              variant={connected ? 'success' : 'error'}
              className="text-[10px] shrink-0"
            >
              {connected ? t('Connected') : t('Not connected')}
            </Badge>
          </div>
          <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
            {t(
              'Paste this prompt into any coding agent. It follows a public setup page to install Appwrite MCP for this project.',
            )}
          </p>
          <button
            type="button"
            className="mt-3 cursor-pointer text-[11px] text-muted-foreground/80 underline-offset-2 hover:text-muted-foreground hover:underline"
            onClick={() => projectConnect.openConnect('mcp')}
            {...analyticsAttrs('connect-agent-mcp-cta')}
          >
            {t('Or install Appwrite MCP manually')}
          </button>
        </div>
        <Button
          type="button"
          variant="brandCta"
          size="sm"
          className="h-9 shrink-0 px-4 text-[13px]"
          onClick={() => void handleCopyPrompt()}
          {...analyticsAttrs('copy-connect-agent-mcp-prompt')}
        >
          {copied ? (
            <Check className="h-4 w-4" />
          ) : (
            <Copy className="h-4 w-4" />
          )}
          {t('Copy prompt')}
        </Button>
      </div>
    </div>
  )
}
