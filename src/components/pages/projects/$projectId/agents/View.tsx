import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import {
  Check,
  Copy,
  Database,
  Folder,
  Globe,
  MessageSquare,
  Users,
  Zap,
} from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { AgentMarks } from '@/components/global/shared/connect-agent-cta/primitives'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  analyticsAttrs,
  getSidebarNavAnalyticsAction,
} from '@/lib/analytics-actions'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import {
  canSeeProjectNavItem,
  canShowAgentMcpConnectCta,
} from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  buildConnectMcpPrompt,
  hasAccountMcpAgentConnected,
} from '@/lib/mcp-adoption'
import {
  useAccountConnectedApps,
  useDismissProjectAgentsLanding,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'

const EXPLORE_APPWRITE_ITEMS = [
  {
    id: 'auth',
    label: 'Auth',
    icon: Users,
    to: '/projects/$projectId/auth',
  },
  {
    id: 'databases',
    label: 'Databases',
    icon: Database,
    to: '/projects/$projectId/databases',
  },
  {
    id: 'storage',
    label: 'Storage',
    icon: Folder,
    to: '/projects/$projectId/storage',
  },
  {
    id: 'functions',
    label: 'Functions',
    icon: Zap,
    to: '/projects/$projectId/functions',
  },
  {
    id: 'messaging',
    label: 'Messaging',
    icon: MessageSquare,
    to: '/projects/$projectId/messaging',
  },
  {
    id: 'sites',
    label: 'Sites',
    icon: Globe,
    to: '/projects/$projectId/sites',
  },
] as const

function ExploreAppwrite({ projectId }: { projectId: string }) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)

  const items = useMemo(
    () =>
      EXPLORE_APPWRITE_ITEMS.filter((item) =>
        canSeeProjectNavItem(access, features, item.id),
      ),
    [access, features],
  )

  if (items.length === 0) return null

  return (
    <div className="space-y-5">
      <h4 className="text-center text-[15px] font-semibold text-foreground">
        {t('Explore Appwrite')}
      </h4>
      <p className="mx-auto max-w-xl text-center text-[13px] leading-relaxed text-muted-foreground">
        {t('Open a product in this project and keep building with your agent.')}
      </p>
      <div className="grid grid-cols-3 gap-3">
        {items.map((item) => {
          const Icon = item.icon
          const analyticsAction = getSidebarNavAnalyticsAction(item.id)
          return (
            <Link
              key={item.id}
              to={item.to}
              params={{ projectId }}
              className="inline-flex h-9 min-w-0 items-center justify-start gap-2 rounded-md border border-border bg-muted/20 px-3 text-[12px] font-medium text-foreground hover:bg-accent/15"
              {...(analyticsAction ? analyticsAttrs(analyticsAction) : {})}
            >
              <Icon
                className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                aria-hidden
              />
              <span className="min-w-0 truncate">{t(item.label)}</span>
            </Link>
          )
        })}
      </div>
    </div>
  )
}

export function View() {
  const t = useT()
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false })
  const { isAuthenticated, account } = useAuth()
  const { features, isSelfHosted } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { data: connectedApps } = useAccountConnectedApps({
    enabled: isAuthenticated,
  })
  const projectConnect = useProjectConnectDialog()
  const [copied, setCopied] = useState(false)
  const dismissLanding = useDismissProjectAgentsLanding()

  const canShow =
    !!projectId &&
    isAuthenticated &&
    canShowAgentMcpConnectCta(access, features)

  const connected = hasAccountMcpAgentConnected(connectedApps?.groups)
  const projectName = project?.name ?? projectId ?? ''
  const connectPrompt = useMemo(
    () =>
      buildConnectMcpPrompt({
        projectId: projectId ?? '',
        projectName,
        isSelfHosted,
        endpoint: getApiEndpoint(project?.region),
      }),
    [projectId, projectName, isSelfHosted, project?.region],
  )

  if (!canShow || !projectConnect || !projectId) {
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
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
        <div className="mx-auto my-auto flex w-full max-w-3xl flex-col items-center px-4 py-12 sm:px-6 sm:py-20">
          <AgentMarks size="lg" />
          <div className="mt-6">
            <Badge
              variant={connected ? 'success' : 'error'}
              className="text-[10px] shrink-0"
            >
              {connected ? t('Connected') : t('Not connected')}
            </Badge>
          </div>
          <h1 className="mt-6 text-center text-[28px] font-semibold tracking-tight text-foreground">
            {t('Connect Appwrite with your agents')}
          </h1>
          <p className="mt-4 max-w-2xl text-center text-[14px] leading-relaxed text-muted-foreground">
            {t(
              'Paste this prompt into any coding agent. It follows a public setup page to install Appwrite MCP for this project.',
            )}
          </p>
          <Button
            type="button"
            variant="brandCta"
            size="lg"
            className="mt-10 h-11 min-w-[14rem] px-6 text-[15px]"
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
          <button
            type="button"
            className="mt-4 cursor-pointer text-[11px] text-muted-foreground/80 underline-offset-2 hover:text-muted-foreground hover:underline"
            onClick={() => projectConnect.openConnect('mcp')}
            {...analyticsAttrs('connect-agent-mcp-cta')}
          >
            {t('Or install Appwrite MCP manually')}
          </button>
          <AgentsLandingSkip
            canSavePrefs={Boolean(account)}
            isPending={dismissLanding.isPending}
            onSkip={() => {
              dismissLanding.mutate(projectId, {
                onSuccess: () => {
                  navigate({
                    to: '/projects/$projectId/overview',
                    params: { projectId },
                    replace: true,
                  })
                },
                onError: () => {
                  toast.error(t('Failed to update preferences'))
                },
              })
            }}
          />
          <div className="mt-16 w-full">
            <MCPSection
              compact
              parts="try"
              projectId={projectId}
              projectName={projectName}
            />
          </div>
          <div className="mt-16 w-full">
            <ExploreAppwrite projectId={projectId} />
          </div>
        </div>
      </div>
    </div>
  )
}

function AgentsLandingSkip({
  canSavePrefs,
  isPending,
  onSkip,
}: {
  canSavePrefs: boolean
  isPending: boolean
  onSkip: () => void
}) {
  const t = useT()
  const skipDisabled = !canSavePrefs || isPending
  const skipControl = (
    <button
      type="button"
      className="cursor-pointer text-[11px] text-muted-foreground/80 underline-offset-2 hover:text-muted-foreground hover:underline disabled:cursor-not-allowed disabled:opacity-50"
      disabled={skipDisabled}
      onClick={() => {
        if (skipDisabled) return
        onSkip()
      }}
    >
      {t('Skip for this project')}
    </button>
  )

  if (canSavePrefs) {
    return <div className="mt-3">{skipControl}</div>
  }

  return (
    <div className="mt-3">
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">{skipControl}</span>
        </TooltipTrigger>
        <TooltipContent>
          <p className="text-[13px]">{t('Preferences are unavailable.')}</p>
        </TooltipContent>
      </Tooltip>
    </div>
  )
}
