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
import { McpIcon } from '@/components/global/shared/McpIcon'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  analyticsAttrs,
  getSidebarNavAnalyticsAction,
} from '@/lib/analytics-actions'
import { formatApiEndpointDisplay, getApiEndpoint } from '@/lib/appwrite/sdk'
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
  useAccountConsents,
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

function ProjectIdEndpointChips({
  projectId,
  endpoint,
}: {
  projectId: string
  endpoint: string
}) {
  const t = useT()
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const endpointDisplay = formatApiEndpointDisplay(endpoint)

  const copyToClipboard = (text: string, field: string) => {
    void navigator.clipboard.writeText(text)
    setCopiedField(field)
    window.setTimeout(() => setCopiedField(null), 2000)
  }

  return (
    <TooltipProvider delayDuration={0}>
      <div className="flex items-center gap-2 overflow-hidden">
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={() => copyToClipboard(projectId, 'projectId')}
              className="flex min-w-0 cursor-pointer items-center gap-1.5 rounded-md bg-muted/50 px-2.5 py-1.5 font-mono text-[12px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <span className="max-w-[120px] truncate sm:max-w-[180px]">
                {projectId}
              </span>
              {copiedField === 'projectId' ? (
                <Check className="h-3 w-3 shrink-0 text-emerald-500" />
              ) : (
                <Copy className="h-3 w-3 shrink-0" />
              )}
            </button>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            <p>{t('Copy project ID')}</p>
          </TooltipContent>
        </Tooltip>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={() => copyToClipboard(endpoint, 'endpoint')}
              className="flex min-w-0 cursor-pointer items-center gap-1.5 rounded-md bg-muted/50 px-2.5 py-1.5 font-mono text-[12px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <span className="max-w-[100px] truncate sm:max-w-[160px]">
                {endpointDisplay}
              </span>
              {copiedField === 'endpoint' ? (
                <Check className="h-3 w-3 shrink-0 text-emerald-500" />
              ) : (
                <Copy className="h-3 w-3 shrink-0" />
              )}
            </button>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            <p>{t('Copy API endpoint')}</p>
          </TooltipContent>
        </Tooltip>
      </div>
    </TooltipProvider>
  )
}

export function View() {
  const t = useT()
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false })
  const { isAuthenticated, account } = useAuth()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { data: consents } = useAccountConsents({
    enabled: isAuthenticated,
  })
  const projectConnect = useProjectConnectDialog()
  const [copied, setCopied] = useState(false)
  const dismissLanding = useDismissProjectAgentsLanding()

  const canShow =
    !!projectId &&
    isAuthenticated &&
    canShowAgentMcpConnectCta(access, features)

  const connected = hasAccountMcpAgentConnected(consents)
  const projectName = project?.name ?? projectId ?? ''
  const endpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )
  const connectPrompt = useMemo(
    () =>
      buildConnectMcpPrompt({
        projectId: projectId ?? '',
        projectName,
        endpoint,
      }),
    [projectId, projectName, endpoint],
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
    <div className="relative flex h-full min-h-0 flex-col">
      <div className="legacy-theme-header">
        <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <h1 className="min-w-0 truncate text-[17px] font-medium text-foreground">
            {t('Agents')}
          </h1>
          <ProjectIdEndpointChips projectId={projectId} endpoint={endpoint} />
        </div>
        <div className="border-b border-border" />
      </div>
      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto pb-12 sm:pb-14">
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
          <h1 className="mt-6 text-center font-aeonik-pro text-[28px] font-normal tracking-tight text-foreground">
            {t('Connect Appwrite with your agents')}
          </h1>
          <p className="mt-4 max-w-2xl text-center text-[14px] leading-relaxed text-muted-foreground">
            {t(
              'Paste this prompt into any coding agent. It follows a public setup page to install Appwrite MCP for this project.',
            )}
          </p>
          <div className="mt-10 flex flex-wrap items-center justify-center gap-3">
            <Button
              type="button"
              variant="brandCta"
              size="lg"
              className="h-11 min-w-[14rem] px-6 text-[15px]"
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
            <Button
              type="button"
              variant="secondary"
              size="lg"
              className="h-11 px-6 text-[15px]"
              onClick={() => projectConnect.openConnect('mcp')}
              {...analyticsAttrs('connect-agent-mcp-cta')}
            >
              <McpIcon className="h-4 w-4" />
              {t('Install Appwrite MCP')}
            </Button>
          </div>
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
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 border-border/70 bg-background/90 px-3 text-[12px] font-normal text-muted-foreground shadow-sm backdrop-blur-sm hover:bg-muted/50 hover:text-foreground"
      disabled={skipDisabled}
      onClick={onSkip}
    >
      {t('Skip for this project')}
    </Button>
  )

  return (
    <div className="pointer-events-none absolute bottom-4 end-4 z-10 text-end sm:bottom-6 sm:end-6">
      <div className="pointer-events-auto">
        {canSavePrefs ? (
          skipControl
        ) : (
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex">{skipControl}</span>
            </TooltipTrigger>
            <TooltipContent>
              <p className="text-[13px]">{t('Preferences are unavailable.')}</p>
            </TooltipContent>
          </Tooltip>
        )}
      </div>
    </div>
  )
}
