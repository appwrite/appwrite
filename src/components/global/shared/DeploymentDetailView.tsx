import { useState, useMemo, useRef, useEffect, useCallback } from 'react'
import * as React from 'react'
import { Link } from '@tanstack/react-router'
import {
  GitBranch,
  GitCommit,
  Search,
  Copy,
  Download,
  Trash2,
  XCircle,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ChevronUp,
  Play,
  ArrowUp,
  ArrowDown,
  HelpCircle,
  FileCode,
  Package,
  ExternalLink,
  RefreshCw,
  Sun,
  Moon,
  CheckCircle2,
} from 'lucide-react'
import { useTheme } from 'next-themes'
import {
  getDeploymentStatusBadge,
  isDeploymentCompleted,
  isDeploymentInProgress,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import { getDeploymentRepositoryWebUrl } from '@/lib/utils/deployment-repository-url'
import { FixWithAgentDropdown } from '@/components/global/shared/FixWithAgentDropdown'
import { generateDeploymentAIFixPrompt } from '@/lib/deployment-ai-fix-prompt'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  BuildLogsView,
  stripAnsiForClipboard,
} from '@/components/global/shared/BuildLogsView'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  TooltipProvider,
  TooltipTrigger,
  TooltipContent,
} from '@/components/ui/tooltip'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from '@/components/ui/drawer'
import { toast } from 'sonner'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useLocation } from '@tanstack/react-router'
import { ImageFormat, type Models } from '@appwrite.io/console'
import {
  useDeploymentProxyRules,
  useFunctionDeploymentProxyRules,
} from '@/lib/react-query/hooks'
import { getSiteScreenshotFilePreviewUrl } from '@/lib/appwrite/sdk'
import { getVcsProvider } from '@/lib/vcs/providers'
import {
  SITE_SCREENSHOTS_BUCKET_ID,
  SITE_SCREENSHOT_CARD_WIDTH,
  SITE_SCREENSHOT_CARD_HEIGHT,
} from '@/lib/sites/screenshot-preview-sizes'
import { useAvifSupport } from '@/lib/avif-support'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { cn } from '@/lib/utils'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import { useT } from '@/lib/i18n/translate'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { domainUrl } from '@/lib/domains/url'

function formatSize(bytes: number | bigint): string {
  return formatDecimalBytes(bytes)
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

/** Popovers/menus/dialogs must sit above fullscreen WizardLayout (z-[9998]). */
const WIZARD_PORTAL_Z_DROPDOWN = 'z-[10050]'
const WIZARD_PORTAL_Z_POPOVER = 'z-[10050]'
const WIZARD_DIALOG_OVERLAY_Z = 'z-[10050]'
const WIZARD_DIALOG_CONTENT_Z = 'z-[10051]'
/** Drawer must stack above fullscreen WizardLayout (z-[9998]); close drawer before opening dialogs (z-[10050]). */
const WIZARD_DRAWER_OVERLAY_Z = 'z-[10052]'
const WIZARD_DRAWER_CONTENT_Z = 'z-[10053]'

/** After copying a deployment URL, hide the copy control after this delay (ms). */
const URL_COPY_HIDE_DELAY_MS = 500

// Detect VCS provider from deployment
function detectVcsProvider(
  deployment: unknown,
): { name: string; icon: React.ReactNode } | null {
  // Check for providerRepositoryUrl which contains the provider domain
  if (deployment.providerRepositoryUrl) {
    const url = deployment.providerRepositoryUrl.toLowerCase()
    if (url.includes('github.com')) {
      const { label, Icon } = getVcsProvider('github')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (url.includes('gitlab.com')) {
      const { label, Icon } = getVcsProvider('gitlab')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (url.includes('bitbucket.org') || url.includes('bitbucket.com')) {
      const { label, Icon } = getVcsProvider('bitbucket')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (url.includes('cursor.com')) {
      const { label, Icon } = getVcsProvider('origin')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
  }

  // Check for vcsProvider field (if available)
  if (deployment.vcsProvider) {
    const provider = deployment.vcsProvider.toLowerCase()
    if (provider === 'github') {
      const { label, Icon } = getVcsProvider('github')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (provider === 'gitlab') {
      const { label, Icon } = getVcsProvider('gitlab')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (provider === 'bitbucket') {
      const { label, Icon } = getVcsProvider('bitbucket')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
    if (provider === 'origin') {
      const { label, Icon } = getVcsProvider('origin')
      return { name: label, icon: <Icon className="h-4 w-4" /> }
    }
  }

  // Check if type is 'git' or 'vcs' (generic VCS deployment)
  if (deployment.type === 'git' || deployment.type === 'vcs') {
    // If we have repository info but can't determine provider, show generic Git icon
    if (deployment.providerRepositoryUrl || deployment.providerRepositoryId) {
      return {
        name: 'Git',
        icon: <GitBranch className="h-4 w-4" />,
      }
    }
  }

  return null
}

/**
 * Get VCS provider type from deployment
 */
function getVcsProviderType(
  deployment: unknown,
): 'github' | 'gitlab' | 'bitbucket' | 'origin' | null {
  // Check provider from URL or vcsProvider field
  if (deployment.providerRepositoryUrl) {
    const url = deployment.providerRepositoryUrl.toLowerCase()
    if (url.includes('github.com')) return 'github'
    if (url.includes('gitlab.com')) return 'gitlab'
    if (url.includes('bitbucket.org') || url.includes('bitbucket.com'))
      return 'bitbucket'
    if (url.includes('cursor.com')) return 'origin'
  }

  // Fallback to vcsProvider field
  if (deployment.vcsProvider) {
    const provider = deployment.vcsProvider.toLowerCase()
    if (provider === 'github') return 'github'
    if (provider === 'gitlab') return 'gitlab'
    if (provider === 'bitbucket') return 'bitbucket'
    if (provider === 'origin') return 'origin'
  }

  return null
}

/**
 * Build commit URL from deployment VCS provider info
 */
function getCommitUrl(deployment: unknown): string | null {
  if (
    !deployment.providerCommitHash ||
    !deployment.providerRepositoryOwner ||
    !deployment.providerRepositoryName
  ) {
    return null
  }

  const owner = deployment.providerRepositoryOwner
  const repo = deployment.providerRepositoryName
  const commitHash = deployment.providerCommitHash
  const provider = getVcsProviderType(deployment)

  if (!provider) return null

  if (provider === 'github') {
    return `https://github.com/${owner}/${repo}/commit/${commitHash}`
  }
  if (provider === 'gitlab') {
    return `https://gitlab.com/${owner}/${repo}/-/commit/${commitHash}`
  }
  if (provider === 'bitbucket') {
    return `https://bitbucket.org/${owner}/${repo}/commits/${commitHash}`
  }
  if (provider === 'origin') {
    return `https://cursor.com/codebase/${owner}/${repo}/commit/${commitHash}`
  }

  return null
}

/**
 * Build branch URL from deployment VCS provider info
 */
function getBranchUrl(deployment: unknown): string | null {
  if (
    !deployment.providerBranch ||
    !deployment.providerRepositoryOwner ||
    !deployment.providerRepositoryName
  ) {
    return null
  }

  const owner = deployment.providerRepositoryOwner
  const repo = deployment.providerRepositoryName
  const branch = deployment.providerBranch
  const provider = getVcsProviderType(deployment)

  if (!provider) return null

  if (provider === 'github') {
    return `https://github.com/${owner}/${repo}/tree/${branch}`
  }
  if (provider === 'gitlab') {
    return `https://gitlab.com/${owner}/${repo}/-/tree/${branch}`
  }
  if (provider === 'bitbucket') {
    return `https://bitbucket.org/${owner}/${repo}/src/${branch}`
  }
  if (provider === 'origin') {
    return `https://cursor.com/codebase/${owner}/${repo}/tree/${branch}`
  }

  return null
}

export interface DeploymentDetailViewConfig {
  // Data
  projectId: string
  resourceId: string // siteId or functionId
  deploymentId: string
  deployment: Models.Deployment | undefined
  isLoading: boolean
  parentResource:
    | {
        name?: string
        deploymentId?: string
        runtime?: string
        framework?: string
      }
    | undefined // site or function
  deployments: Models.Deployment[]
  relatedData?: { total?: number } // logs or executions count

  // Navigation
  deploymentDetailRoute: string // Route pattern for deployment detail
  listRoute: string // Route pattern for parent resource list
  relatedRoute?: string // Route pattern for related data (logs/executions)

  // Actions
  onDelete: (deploymentId: string) => Promise<void>
  /** Cancel an in-progress build (stops build, deployment remains). When not provided, Cancel button is hidden when building. */
  onCancelBuild?: (deploymentId: string) => Promise<void>
  onDownloadSource: (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => void
  onDownloadBuild: (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => void
  onRedeploy?: (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => Promise<void>
  onActivate?: (
    projectId: string,
    resourceId: string,
    deploymentId: string,
  ) => Promise<void>
  onNavigateToRelated?: (deploymentId: string) => void

  // UI
  relatedDataLabel?: string // "Logs" or "Executions"
  showRuntime?: boolean // Show runtime metadata (for functions)
  RuntimeIcon?: React.ComponentType<{
    runtime: string
    size?: string
    className?: string
  }>

  // Query invalidation
  invalidateQueries: Array<string | string[]>

  // Fallback path
  fallbackPath: string
}

export function DeploymentDetailView({
  projectId,
  resourceId,
  deploymentId,
  deployment,
  isLoading,
  parentResource,
  deployments,
  deploymentDetailRoute,
  listRoute,
  onDelete,
  onCancelBuild,
  onDownloadSource,
  onDownloadBuild,
  onRedeploy,
  onActivate,
  showRuntime = false,
  RuntimeIcon,
  invalidateQueries,
  fallbackPath,
}: DeploymentDetailViewConfig) {
  const t = useT()
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [logsSearch, setLogsSearch] = useState('')
  /** Selected build log line numbers (1-based, original file lines). */
  const [selectedLogLines, setSelectedLogLines] = useState<Set<number>>(
    () => new Set(),
  )
  const lineAnchorRef = useRef<number | null>(null)
  const prevHydratedDeploymentIdRef = useRef<string | undefined>(undefined)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)
  const [activateDialogOpen, setActivateDialogOpen] = useState(false)
  const [deploymentActionsDrawerOpen, setDeploymentActionsDrawerOpen] =
    useState(false)
  const logsContainerRef = useRef<HTMLDivElement>(null)
  const lineRefs = useRef<Map<number, HTMLDivElement>>(new Map())
  /** Set on first scroll; until then we auto-scroll so initial load follows tail. */
  const hasUserScrolledRef = useRef(false)
  const [isAtTop, setIsAtTop] = useState(true)
  const [isAtBottom, setIsAtBottom] = useState(false)
  /** URL row copy icon stays hidden after copy until pointer leaves that row. */
  const [urlCopyHiddenUntilLeave, setUrlCopyHiddenUntilLeave] = useState<
    string | null
  >(null)
  const urlCopyHideAfterCopyTimeoutRef = useRef<ReturnType<
    typeof setTimeout
  > | null>(null)
  /** Commit hash copy icon stays hidden after copy until pointer leaves that row. */
  const [commitCopyHiddenUntilLeave, setCommitCopyHiddenUntilLeave] =
    useState(false)
  const commitCopyHideAfterCopyTimeoutRef = useRef<ReturnType<
    typeof setTimeout
  > | null>(null)
  /** Branch name copy icon stays hidden after copy until pointer leaves that row. */
  const [branchCopyHiddenUntilLeave, setBranchCopyHiddenUntilLeave] =
    useState(false)
  const branchCopyHideAfterCopyTimeoutRef = useRef<ReturnType<
    typeof setTimeout
  > | null>(null)

  // Live elapsed seconds when deployment is processing/building (updates every second)
  const [elapsedSeconds, setElapsedSeconds] = useState(0)
  useEffect(() => {
    if (!deployment?.$createdAt || !isDeploymentInProgress(deployment.status))
      return
    const tick = () => {
      const created = new Date(deployment.$createdAt).getTime()
      setElapsedSeconds(Math.floor((Date.now() - created) / 1000))
    }
    tick()
    const interval = setInterval(tick, 1000)
    return () => clearInterval(interval)
  }, [deployment?.$createdAt, deployment?.status])

  // Hydrate log line selection from the URL when the deployment changes (not on every search change).
  useEffect(() => {
    const depId = deployment?.$id
    if (depId === undefined) return
    if (prevHydratedDeploymentIdRef.current === depId) return
    prevHydratedDeploymentIdRef.current = depId

    const searchParams = new URLSearchParams(
      typeof location.search === 'string' ? location.search : '',
    )
    const lineParam = searchParams.get('line')
    const parsed = lineParam ? parseInt(lineParam, 10) : NaN
    const line = !isNaN(parsed) ? parsed : null
    setSelectedLogLines(line != null ? new Set([line]) : new Set())
    lineAnchorRef.current = line
  }, [deployment?.$id])

  // Determine site vs function from the route only - never infer from resource IDs
  // (e.g. a function ID like "website-api" contains "site" and would misclassify as a site).
  const isSiteDeployment = deploymentDetailRoute.includes('/sites/')
  const parentResourceParam = isSiteDeployment ? 'siteId' : 'functionId'

  /** Prefer the loaded model's id so mutations match the deployment the UI is showing. */
  const apiDeploymentId = deployment?.$id ?? deploymentId

  // Get current deployment index and find previous/next
  const deploymentIndex = useMemo(() => {
    if (!deployments || !apiDeploymentId) return -1
    return deployments.findIndex((d) => d.$id === apiDeploymentId)
  }, [deployments, apiDeploymentId])

  const previousDeployment =
    deploymentIndex > 0 ? deployments[deploymentIndex - 1] : null
  const nextDeployment =
    deploymentIndex >= 0 && deploymentIndex < (deployments.length || 0) - 1
      ? deployments[deploymentIndex + 1]
      : null

  // Check if this is the active deployment
  const isActiveDeployment = parentResource?.deploymentId === deploymentId

  // Fetch proxy rules for this deployment
  const siteProxyRules = useDeploymentProxyRules(
    isSiteDeployment ? projectId : null,
    isSiteDeployment ? resourceId : null,
    isSiteDeployment ? apiDeploymentId : null,
  )

  const functionProxyRules = useFunctionDeploymentProxyRules(
    !isSiteDeployment ? projectId : null,
    !isSiteDeployment ? resourceId : null,
    !isSiteDeployment ? apiDeploymentId : null,
  )

  const proxyRules = isSiteDeployment ? siteProxyRules : functionProxyRules

  /** Domains from proxy rules returned for this deployment ID only (SDK queries). */
  const visitEntries = useMemo(() => {
    const domains = new Set<string>()
    for (const r of proxyRules.rules) {
      if (!r.domain) continue
      if (r.type !== 'deployment' && r.type !== 'redirect') continue
      domains.add(r.domain)
    }
    return Array.from(domains)
  }, [proxyRules.rules])

  useEffect(() => {
    if (urlCopyHideAfterCopyTimeoutRef.current) {
      clearTimeout(urlCopyHideAfterCopyTimeoutRef.current)
      urlCopyHideAfterCopyTimeoutRef.current = null
    }
    setUrlCopyHiddenUntilLeave(null)
    if (commitCopyHideAfterCopyTimeoutRef.current) {
      clearTimeout(commitCopyHideAfterCopyTimeoutRef.current)
      commitCopyHideAfterCopyTimeoutRef.current = null
    }
    setCommitCopyHiddenUntilLeave(false)
    if (branchCopyHideAfterCopyTimeoutRef.current) {
      clearTimeout(branchCopyHideAfterCopyTimeoutRef.current)
      branchCopyHideAfterCopyTimeoutRef.current = null
    }
    setBranchCopyHiddenUntilLeave(false)
  }, [deployment?.$id])

  useEffect(() => {
    return () => {
      if (urlCopyHideAfterCopyTimeoutRef.current) {
        clearTimeout(urlCopyHideAfterCopyTimeoutRef.current)
        urlCopyHideAfterCopyTimeoutRef.current = null
      }
      if (commitCopyHideAfterCopyTimeoutRef.current) {
        clearTimeout(commitCopyHideAfterCopyTimeoutRef.current)
        commitCopyHideAfterCopyTimeoutRef.current = null
      }
      if (branchCopyHideAfterCopyTimeoutRef.current) {
        clearTimeout(branchCopyHideAfterCopyTimeoutRef.current)
        branchCopyHideAfterCopyTimeoutRef.current = null
      }
    }
  }, [])

  const { resolvedTheme } = useTheme()
  const [sidebarScreenshotLoaded, setSidebarScreenshotLoaded] = useState(false)
  const [sidebarScreenshotThemeOverride, setSidebarScreenshotThemeOverride] =
    useState<'light' | 'dark' | null>(null)

  const defaultScreenshotTheme = useMemo((): 'light' | 'dark' => {
    if (typeof window === 'undefined') return 'dark'
    if (resolvedTheme === 'dark') return 'dark'
    if (resolvedTheme === 'light') return 'light'
    if (
      resolvedTheme === 'system' &&
      window.matchMedia('(prefers-color-scheme: dark)').matches
    )
      return 'dark'
    return 'light'
  }, [resolvedTheme])

  const sidebarScreenshotTheme =
    sidebarScreenshotThemeOverride ?? defaultScreenshotTheme

  const avifSupported = useAvifSupport()

  const sidebarScreenshotFileId = deployment
    ? sidebarScreenshotTheme === 'dark'
      ? deployment.screenshotDark
      : deployment.screenshotLight
    : null

  useEffect(() => {
    setSidebarScreenshotLoaded(false)
  }, [deployment?.$id, sidebarScreenshotTheme, sidebarScreenshotFileId])

  // Check if deployment failed (including timeout)
  const isDeploymentFailed = deployment
    ? deployment.status === 'failed' ||
      isDeploymentTimeout(deployment.status, deployment.$createdAt)
    : false

  // Generate AI fix prompt
  const aiFixPrompt = useMemo(() => {
    if (!deployment || !isDeploymentFailed) return ''
    return generateDeploymentAIFixPrompt(
      deployment,
      parentResource?.runtime,
      parentResource?.name,
      isSiteDeployment,
    )
  }, [
    deployment,
    isDeploymentFailed,
    parentResource?.runtime,
    parentResource?.name,
    isSiteDeployment,
  ])

  // Get VCS provider info
  const vcsProvider = deployment ? detectVcsProvider(deployment) : null

  // Get repository URLs
  const repositoryUrl = deployment
    ? getDeploymentRepositoryWebUrl(deployment)
    : null
  const commitUrl = deployment ? getCommitUrl(deployment) : null
  const branchUrl = deployment ? getBranchUrl(deployment) : null

  const resolvedCommitUrl = deployment?.providerCommitUrl || commitUrl || null
  const resolvedBranchUrl = deployment?.providerBranchUrl || branchUrl || null

  // Get status badge
  const statusBadge = deployment
    ? getDeploymentStatusBadge(deployment.status, deployment.$createdAt)
    : null

  const buildLogs = deployment?.buildLogs || ''

  const deploymentDetailSidebar = useMemo(() => {
    if (!deployment) return null

    const screenshotId =
      sidebarScreenshotTheme === 'dark'
        ? deployment.screenshotDark
        : deployment.screenshotLight
    const screenshotUrl =
      isSiteDeployment && screenshotId && projectId
        ? getSiteScreenshotFilePreviewUrl(projectId, {
            bucketId: SITE_SCREENSHOTS_BUCKET_ID,
            fileId: screenshotId,
            width: SITE_SCREENSHOT_CARD_WIDTH,
            height: SITE_SCREENSHOT_CARD_HEIGHT,
            output: avifSupported ? ImageFormat.Avif : undefined,
          })
        : null

    const typeLabel =
      deployment.type === 'cli'
        ? 'CLI'
        : deployment.type === 'manual'
          ? t('Manual')
          : deployment.type === 'vcs'
            ? 'VCS'
            : deployment.type || 'N/A'

    return (
      <div className="divide-y divide-border pb-4 [&>section:first-of-type]:!pt-0 [&>section:first-of-type]:pb-3">
        {isSiteDeployment && (
          <section className="space-y-2.5 py-3">
            <div className="flex items-center justify-between gap-2">
              <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Preview')}
              </h3>
              <div className="flex items-center gap-0.5 rounded border border-border/60 bg-muted/40 p-0.5">
                <button
                  type="button"
                  onClick={() => {
                    setSidebarScreenshotThemeOverride('light')
                    setSidebarScreenshotLoaded(false)
                  }}
                  className={cn(
                    'rounded p-1 transition-colors',
                    sidebarScreenshotTheme === 'light'
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                  )}
                  title={t('Light screenshot')}
                >
                  <Sun className="h-3 w-3" />
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setSidebarScreenshotThemeOverride('dark')
                    setSidebarScreenshotLoaded(false)
                  }}
                  className={cn(
                    'rounded p-1 transition-colors',
                    sidebarScreenshotTheme === 'dark'
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                  )}
                  title={t('Dark screenshot')}
                >
                  <Moon className="h-3 w-3" />
                </button>
              </div>
            </div>
            <div className="relative w-full aspect-video overflow-hidden rounded border border-border bg-muted">
              {screenshotUrl ? (
                <div className="absolute inset-0">
                  <img
                    key={screenshotId}
                    src={screenshotUrl}
                    alt={t('Deployment screenshot')}
                    onLoad={() => setSidebarScreenshotLoaded(true)}
                    className={cn(
                      'h-full w-full object-cover object-top transition-opacity duration-300',
                      sidebarScreenshotLoaded ? 'opacity-100' : 'opacity-0',
                    )}
                  />
                  {parentResource?.framework ? (
                    <div className="absolute bottom-1 start-1 flex h-6 w-6 items-center justify-center rounded border border-border/60 bg-background/90">
                      <FrameworkIcon
                        framework={parentResource.framework}
                        size="sm"
                      />
                    </div>
                  ) : null}
                </div>
              ) : (
                <div className="flex h-full items-center justify-center px-2">
                  <p className="text-center text-[10px] leading-snug text-muted-foreground">
                    {isDeploymentCompleted(deployment.status)
                      ? t('No preview')
                      : t('Not ready')}
                  </p>
                </div>
              )}
            </div>
          </section>
        )}

        {(isSiteDeployment || visitEntries.length > 0) && (
          <section className="space-y-2 py-3">
            <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('URLs')}
            </h3>
            {!isActiveDeployment && visitEntries.length > 0 ? (
              <p className="text-[10px] leading-snug text-muted-foreground">
                {t(
                  'Live traffic uses the active deployment until you activate this one.',
                )}
              </p>
            ) : null}
            {visitEntries.length === 0 ? (
              isSiteDeployment ? (
                <p className="text-[10px] leading-snug text-muted-foreground">
                  {t('No proxy rules reference this deployment.')}
                </p>
              ) : null
            ) : (
              <ul className="space-y-0">
                {visitEntries.map((domain) => {
                  const copyHiddenUntilLeave =
                    urlCopyHiddenUntilLeave === domain
                  return (
                    <li
                      key={domain}
                      className="group flex min-w-0 items-center gap-1 py-0.5"
                      onMouseLeave={() => {
                        if (urlCopyHideAfterCopyTimeoutRef.current) {
                          clearTimeout(urlCopyHideAfterCopyTimeoutRef.current)
                          urlCopyHideAfterCopyTimeoutRef.current = null
                        }
                        setUrlCopyHiddenUntilLeave((blocked) =>
                          blocked === domain ? null : blocked,
                        )
                      }}
                    >
                      <a
                        href={domainUrl(domain)}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={`Open ${domain} in new tab`}
                        className="link-neutral flex min-w-0 flex-1 items-center gap-1 overflow-hidden font-mono text-[11px]"
                      >
                        <span className="min-w-0 truncate">{domain}</span>
                        <ExternalLink
                          className="h-3 w-3 shrink-0 text-muted-foreground opacity-70 group-hover:opacity-100 group-hover:text-foreground"
                          aria-hidden
                        />
                      </a>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className={cn(
                          'h-6 w-6 shrink-0 p-0 text-muted-foreground transition-opacity duration-150',
                          'hover:bg-muted/60 hover:text-foreground',
                          copyHiddenUntilLeave
                            ? 'pointer-events-none opacity-0'
                            : 'pointer-events-none opacity-0 group-hover:pointer-events-auto group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:opacity-100',
                        )}
                        onClick={(e) => {
                          const el = e.currentTarget as HTMLButtonElement
                          void navigator.clipboard.writeText(domainUrl(domain))
                          toast.success(t('URL copied'))
                          el.blur()
                          if (urlCopyHideAfterCopyTimeoutRef.current) {
                            clearTimeout(urlCopyHideAfterCopyTimeoutRef.current)
                          }
                          urlCopyHideAfterCopyTimeoutRef.current =
                            window.setTimeout(() => {
                              urlCopyHideAfterCopyTimeoutRef.current = null
                              setUrlCopyHiddenUntilLeave(domain)
                            }, URL_COPY_HIDE_DELAY_MS)
                        }}
                      >
                        <Copy className="h-3 w-3" />
                      </Button>
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
        )}

        {(deployment.providerCommitMessage ||
          deployment.providerCommitHash ||
          deployment.providerCommitAuthor) && (
          <section className="space-y-2 py-3">
            <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Commit')}
            </h3>
            {deployment.providerCommitAuthor ? (
              <div className="min-w-0">
                {deployment.providerCommitAuthorUrl ? (
                  <a
                    href={deployment.providerCommitAuthorUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="link-neutral truncate text-[11px]"
                    title={deployment.providerCommitAuthor}
                  >
                    {deployment.providerCommitAuthor}
                  </a>
                ) : (
                  <span
                    className="truncate text-[11px] font-medium text-foreground"
                    title={deployment.providerCommitAuthor}
                  >
                    {deployment.providerCommitAuthor}
                  </span>
                )}
              </div>
            ) : null}
            {deployment.providerCommitMessage ? (
              <p className="break-words text-[11px] leading-snug whitespace-pre-wrap text-foreground">
                {deployment.providerCommitMessage}
              </p>
            ) : null}
            {deployment.providerCommitHash ? (
              <div
                className="group flex min-w-0 items-center gap-1 pt-0.5"
                onMouseLeave={() => {
                  if (commitCopyHideAfterCopyTimeoutRef.current) {
                    clearTimeout(commitCopyHideAfterCopyTimeoutRef.current)
                    commitCopyHideAfterCopyTimeoutRef.current = null
                  }
                  setCommitCopyHiddenUntilLeave(false)
                }}
              >
                <GitCommit className="h-3 w-3 shrink-0 text-muted-foreground" />
                <div className="flex min-w-0 flex-1 items-center gap-1">
                  {resolvedCommitUrl ? (
                    <a
                      href={resolvedCommitUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="min-w-0 truncate font-mono text-[11px] link-neutral"
                      title={deployment.providerCommitHash}
                    >
                      {deployment.providerCommitHash.slice(0, 7)}
                    </a>
                  ) : (
                    <span
                      className="min-w-0 truncate font-mono text-[11px] font-medium text-foreground"
                      title={deployment.providerCommitHash}
                    >
                      {deployment.providerCommitHash.slice(0, 7)}
                    </span>
                  )}
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className={cn(
                      'h-6 w-6 shrink-0 p-0 text-muted-foreground transition-opacity duration-150',
                      'hover:bg-muted/60 hover:text-foreground',
                      commitCopyHiddenUntilLeave
                        ? 'pointer-events-none opacity-0'
                        : 'pointer-events-none opacity-0 group-hover:pointer-events-auto group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:opacity-100',
                    )}
                    aria-label={t('Copy commit hash')}
                    title={t('Copy commit hash')}
                    onClick={(e) => {
                      const el = e.currentTarget as HTMLButtonElement
                      void navigator.clipboard.writeText(
                        deployment.providerCommitHash,
                      )
                      toast.success(t('Commit copied'))
                      el.blur()
                      if (commitCopyHideAfterCopyTimeoutRef.current) {
                        clearTimeout(commitCopyHideAfterCopyTimeoutRef.current)
                      }
                      commitCopyHideAfterCopyTimeoutRef.current =
                        window.setTimeout(() => {
                          commitCopyHideAfterCopyTimeoutRef.current = null
                          setCommitCopyHiddenUntilLeave(true)
                        }, URL_COPY_HIDE_DELAY_MS)
                    }}
                  >
                    <Copy className="h-3 w-3" />
                  </Button>
                </div>
              </div>
            ) : null}
          </section>
        )}

        {deployment.providerBranch ? (
          <section className="space-y-2 py-3">
            <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Branch')}
            </h3>
            <div
              className="group flex min-w-0 items-center gap-1"
              onMouseLeave={() => {
                if (branchCopyHideAfterCopyTimeoutRef.current) {
                  clearTimeout(branchCopyHideAfterCopyTimeoutRef.current)
                  branchCopyHideAfterCopyTimeoutRef.current = null
                }
                setBranchCopyHiddenUntilLeave(false)
              }}
            >
              <GitBranch className="h-3 w-3 shrink-0 text-muted-foreground" />
              <div className="flex min-w-0 flex-1 items-center gap-1">
                {resolvedBranchUrl ? (
                  <a
                    href={resolvedBranchUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="link-neutral min-w-0 truncate text-[11px]"
                  >
                    {deployment.providerBranch}
                  </a>
                ) : (
                  <span className="min-w-0 truncate text-[11px] font-medium text-foreground">
                    {deployment.providerBranch}
                  </span>
                )}
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className={cn(
                    'h-6 w-6 shrink-0 p-0 text-muted-foreground transition-opacity duration-150',
                    'hover:bg-muted/60 hover:text-foreground',
                    branchCopyHiddenUntilLeave
                      ? 'pointer-events-none opacity-0'
                      : 'pointer-events-none opacity-0 group-hover:pointer-events-auto group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:opacity-100',
                  )}
                  aria-label={t('Copy branch name')}
                  title={t('Copy branch name')}
                  onClick={(e) => {
                    const el = e.currentTarget as HTMLButtonElement
                    void navigator.clipboard.writeText(
                      deployment.providerBranch,
                    )
                    toast.success(t('Branch copied'))
                    el.blur()
                    if (branchCopyHideAfterCopyTimeoutRef.current) {
                      clearTimeout(branchCopyHideAfterCopyTimeoutRef.current)
                    }
                    branchCopyHideAfterCopyTimeoutRef.current =
                      window.setTimeout(() => {
                        branchCopyHideAfterCopyTimeoutRef.current = null
                        setBranchCopyHiddenUntilLeave(true)
                      }, URL_COPY_HIDE_DELAY_MS)
                  }}
                >
                  <Copy className="h-3 w-3" />
                </Button>
              </div>
            </div>
          </section>
        ) : null}

        {vcsProvider &&
        deployment.providerRepositoryOwner &&
        deployment.providerRepositoryName ? (
          <section className="space-y-2 py-3">
            <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Repository')}
            </h3>
            <div className="flex min-w-0 items-center gap-1.5">
              <span className="shrink-0 text-muted-foreground [&_svg]:h-3 [&_svg]:w-3">
                {vcsProvider.icon}
              </span>
              {repositoryUrl ? (
                <a
                  href={repositoryUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="link-neutral truncate text-[11px]"
                >
                  {deployment.providerRepositoryOwner}/
                  {deployment.providerRepositoryName}
                </a>
              ) : (
                <span className="truncate text-[11px] font-medium text-foreground">
                  {deployment.providerRepositoryOwner}/
                  {deployment.providerRepositoryName}
                </span>
              )}
            </div>
          </section>
        ) : null}

        <section className="space-y-2 py-3">
          <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Details')}
          </h3>
          <div className="space-y-2 text-[11px]">
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground">{t('Size')}</span>
              <span className="shrink-0 font-medium text-foreground">
                {formatSize(
                  (deployment.buildSize || 0) + (deployment.sourceSize || 0),
                )}
              </span>
            </div>
            <div className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-1">
                <span className="text-muted-foreground">{t('Type')}</span>
                <TooltipProvider delayDuration={0}>
                  <TooltipPrimitive.Root>
                    <TooltipTrigger asChild>
                      <button
                        type="button"
                        className="inline-flex items-center justify-center focus:outline-none"
                      >
                        <HelpCircle className="h-3 w-3 text-muted-foreground hover:text-foreground" />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent
                      side="top"
                      sideOffset={4}
                      className={cn('max-w-xs', WIZARD_PORTAL_Z_POPOVER)}
                    >
                      <p className="text-[12px]">
                        {deployment.type === 'vcs'
                          ? t(
                              'VCS (Version Control System) deployments are triggered from a connected Git repository and enable automatic deployments on code pushes.',
                            )
                          : deployment.type === 'cli'
                            ? t(
                                'CLI deployments are created using the Appwrite command line tool, useful for developer workflows and scripted automation.', // pragma: allowlist secret
                              )
                            : deployment.type === 'manual'
                              ? t(
                                  'Manual deployments are created by uploading code through the Console or API, or by redeploying an existing deployment. Useful for quick testing and re-running builds.',
                                )
                              : t(
                                  'The deployment type indicates how this deployment was created.',
                                )}
                      </p>
                    </TooltipContent>
                  </TooltipPrimitive.Root>
                </TooltipProvider>
              </div>
              <span className="shrink-0 font-medium text-foreground">
                {typeLabel}
              </span>
            </div>
            {showRuntime && parentResource?.runtime && RuntimeIcon ? (
              <div className="flex min-w-0 items-center justify-between gap-2">
                <span className="shrink-0 text-muted-foreground">
                  {t('Runtime')}
                </span>
                <div className="flex min-w-0 items-center gap-1">
                  <RuntimeIcon
                    runtime={parentResource.runtime}
                    size="sm"
                    className="h-3 w-3 shrink-0"
                  />
                  <span className="truncate font-mono font-medium text-foreground">
                    {parentResource.runtime}
                  </span>
                </div>
              </div>
            ) : null}
          </div>
        </section>
      </div>
    )
  }, [
    deployment,
    isSiteDeployment,
    isActiveDeployment,
    projectId,
    parentResource?.framework,
    parentResource?.runtime,
    visitEntries,
    vcsProvider,
    repositoryUrl,
    resolvedCommitUrl,
    resolvedBranchUrl,
    showRuntime,
    RuntimeIcon,
    sidebarScreenshotTheme,
    sidebarScreenshotLoaded,
    urlCopyHiddenUntilLeave,
    commitCopyHiddenUntilLeave,
    branchCopyHiddenUntilLeave,
    avifSupported,
    t,
  ])

  const navigateToDeploymentsList = useCallback(() => {
    navigate({
      to: listRoute as unknown,
      params: { projectId, [parentResourceParam]: resourceId } as unknown,
    })
  }, [navigate, listRoute, projectId, parentResourceParam, resourceId])

  const refetchAndNavigate = async () => {
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
      setCancelBuildDialogOpen(false)
    })
    for (const queryKey of invalidateQueries) {
      const normalizedKey: readonly unknown[] = Array.isArray(queryKey)
        ? queryKey
        : [queryKey]
      await queryClient.refetchQueries({ queryKey: normalizedKey })
    }
    navigateToDeploymentsList()
  }

  // Cancel build mutation (stops build via onCancelBuild; deployment remains, no navigate)
  const cancelBuildMutation = useMutation({
    mutationFn: async () => {
      if (!onCancelBuild) {
        throw new Error(t('Cancel build is not available'))
      }
      return await onCancelBuild(apiDeploymentId)
    },
    onSuccess: async () => {
      closeDialogBeforeOverlayUnmount(() => setCancelBuildDialogOpen(false))
      for (const queryKey of invalidateQueries) {
        const normalizedKey: readonly unknown[] = Array.isArray(queryKey)
          ? queryKey
          : [queryKey]
        await queryClient.refetchQueries({ queryKey: normalizedKey })
      }
      toast.success(t('Build cancelled'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to cancel build'))
    },
  })

  // Delete deployment mutation (blocks when active)
  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (isActiveDeployment) {
        throw new Error(
          t(
            'Cannot delete the active deployment. Please activate another deployment first.',
          ),
        )
      }
      return await onDelete(apiDeploymentId)
    },
    onSuccess: async () => {
      await refetchAndNavigate()
      toast.success(t('Deployment deleted successfully'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete deployment'))
    },
  })

  // Redeploy mutation
  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!onRedeploy) {
        throw new Error(t('Redeploy is not available for this deployment type'))
      }
      return await onRedeploy(projectId, resourceId, apiDeploymentId)
    },
    onSuccess: () => {
      invalidateQueries.forEach((queryKey) => {
        const normalizedKey: readonly unknown[] = Array.isArray(queryKey)
          ? queryKey
          : [queryKey]
        queryClient.invalidateQueries({ queryKey: normalizedKey })
      })
      toast.success(t('Deployment rebuild started'))
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to redeploy'))
    },
  })

  // Activate mutation
  const activateMutation = useMutation({
    mutationFn: async () => {
      if (!onActivate) {
        throw new Error(t('Activate is not available for this deployment type'))
      }
      return await onActivate(projectId, resourceId, apiDeploymentId)
    },
    onSuccess: () => {
      invalidateQueries.forEach((queryKey) => {
        const normalizedKey: readonly unknown[] = Array.isArray(queryKey)
          ? queryKey
          : [queryKey]
        queryClient.invalidateQueries({ queryKey: normalizedKey })
      })
      toast.success(t('Deployment activated successfully'))
      setActivateDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to activate deployment'))
    },
  })

  // Handle download source code
  const handleDownloadSource = () => {
    onDownloadSource(projectId, resourceId, apiDeploymentId)
  }

  // Handle download build output (only when deployment has completed)
  const handleDownloadBuild = () => {
    if (!isDeploymentCompleted(deployment?.status)) return
    onDownloadBuild(projectId, resourceId, apiDeploymentId)
  }

  const syncLineSearchUrl = useCallback(
    (next: Set<number>) => {
      if (next.size === 0) {
        navigate({
          to: location.pathname,
          search: (prev: unknown) => {
            const newSearch = { ...(prev || {}) }
            delete newSearch.line
            return Object.keys(newSearch).length === 0 ? {} : newSearch
          },
          replace: true,
        })
      } else if (next.size === 1) {
        const only = [...next][0]!
        navigate({
          to: location.pathname,
          search: (prev: unknown) => ({ ...(prev || {}), line: only }),
          replace: true,
        })
      } else {
        navigate({
          to: location.pathname,
          search: (prev: unknown) => {
            const newSearch = { ...(prev || {}) }
            delete newSearch.line
            return Object.keys(newSearch).length === 0 ? {} : newSearch
          },
          replace: true,
        })
      }
    },
    [navigate, location.pathname],
  )

  const getLogsTextForClipboard = useCallback(() => {
    if (!buildLogs) return ''
    if (selectedLogLines.size === 0) return buildLogs
    const lines = buildLogs.split('\n')
    const ordered = [...selectedLogLines].sort((a, b) => a - b)
    return ordered
      .map((n) => lines[n - 1] ?? '')
      .map(stripAnsiForClipboard)
      .join('\n')
  }, [buildLogs, selectedLogLines])

  const performCopyLogs = useCallback(async () => {
    if (!buildLogs) {
      toast.error(t('No logs to copy'))
      return
    }

    try {
      await navigator.clipboard.writeText(getLogsTextForClipboard())
      const n = selectedLogLines.size
      if (n === 0) {
        toast.success(t('Logs copied to clipboard'))
      } else if (n === 1) {
        const line = Math.min(...selectedLogLines)
        toast.success(`${t('Copied line')} ${line}`)
      } else {
        toast.success(`Copied ${n} lines`)
      }
    } catch {
      toast.error(t('Failed to copy logs'))
    }
  }, [buildLogs, getLogsTextForClipboard, selectedLogLines, t])

  const handleCopyLogs = () => {
    void performCopyLogs()
  }

  useEffect(() => {
    if (selectedLogLines.size === 0 || !buildLogs) return

    const onKeyDown = (e: KeyboardEvent) => {
      if (!(e.metaKey || e.ctrlKey) || e.key !== 'c') return
      const target = e.target
      if (
        target instanceof HTMLInputElement ||
        target instanceof HTMLTextAreaElement ||
        target instanceof HTMLSelectElement
      ) {
        return
      }
      if (target instanceof HTMLElement && target.isContentEditable) return

      const logsEl = logsContainerRef.current
      const active = document.activeElement
      if (
        logsEl &&
        active instanceof Node &&
        active !== logsEl &&
        !logsEl.contains(active)
      ) {
        return
      }

      const selectionText = window.getSelection()?.toString().trim()
      if (selectionText && selectionText.length > 0) return

      e.preventDefault()
      void performCopyLogs()
    }

    window.addEventListener('keydown', onKeyDown, true)
    return () => window.removeEventListener('keydown', onKeyDown, true)
  }, [selectedLogLines, buildLogs, performCopyLogs])

  // Handle download logs
  const handleDownloadLogs = () => {
    if (!buildLogs) {
      toast.error(t('No logs to download'))
      return
    }

    const blob = new Blob([buildLogs], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `deployment-${apiDeploymentId}-logs.txt`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    toast.success(t('Logs downloaded'))
  }

  // Logs scroll in a dedicated pane; include self then walk up for overflow-y auto/scroll
  const getScrollContainer = useCallback((): HTMLElement | null => {
    if (!logsContainerRef.current) return null
    let element: HTMLElement | null = logsContainerRef.current
    while (element) {
      const style = window.getComputedStyle(element)
      const overflowY = style.overflowY
      if (overflowY === 'auto' || overflowY === 'scroll') {
        return element
      }
      element = element.parentElement
    }
    return null
  }, [])

  // Update scroll position state for scroll-to-top/bottom buttons (controls auto-scroll: follow when at bottom)
  const updateScrollPosition = useCallback(() => {
    const scrollContainer = getScrollContainer()
    if (!scrollContainer) return

    hasUserScrolledRef.current = true
    const { scrollTop, scrollHeight, clientHeight } = scrollContainer
    const threshold = 10 // Small threshold to account for rounding

    setIsAtTop(scrollTop <= threshold)
    setIsAtBottom(scrollTop + clientHeight >= scrollHeight - threshold)
  }, [getScrollContainer])

  // Track scroll position
  useEffect(() => {
    const scrollContainer = getScrollContainer()
    if (!scrollContainer) return

    // Initial check
    updateScrollPosition()

    // Listen for scroll events
    scrollContainer.addEventListener('scroll', updateScrollPosition)

    // Also listen for resize to recalculate
    window.addEventListener('resize', updateScrollPosition)

    return () => {
      scrollContainer.removeEventListener('scroll', updateScrollPosition)
      window.removeEventListener('resize', updateScrollPosition)
    }
  }, [getScrollContainer, updateScrollPosition, buildLogs])

  // Auto-scroll when new logs arrive if user hasn't scrolled or is at bottom (same as scroll-to-bottom control)
  useEffect(() => {
    const shouldFollow = !hasUserScrolledRef.current || isAtBottom
    if (!shouldFollow || !buildLogs) return
    const scrollContainer = getScrollContainer()
    if (scrollContainer) {
      scrollContainer.scrollTop = scrollContainer.scrollHeight
    }
  }, [buildLogs, isAtBottom, getScrollContainer])

  const scrollTargetLine = useMemo(() => {
    if (selectedLogLines.size !== 1) return null
    return Math.min(...selectedLogLines)
  }, [selectedLogLines])

  // Scroll to the sole selected line when it changes (e.g. deep link or single-line selection)
  useEffect(() => {
    if (scrollTargetLine === null) return
    if (!buildLogs) return // Wait for logs to be available

    // Retry mechanism to ensure DOM has updated with refs
    let retryCount = 0
    const maxRetries = 10

    const tryScroll = () => {
      const lineElement = lineRefs.current.get(scrollTargetLine)
      if (!lineElement) {
        // Retry if element not found yet
        if (retryCount < maxRetries) {
          retryCount++
          setTimeout(tryScroll, 100)
        }
        return
      }

      const scrollContainer = getScrollContainer()
      if (!scrollContainer) return

      // Calculate position relative to scroll container
      const containerRect = scrollContainer.getBoundingClientRect()
      const elementRect = lineElement.getBoundingClientRect()
      const relativeTop =
        elementRect.top - containerRect.top + scrollContainer.scrollTop

      // Scroll to line with some padding from top
      scrollContainer.scrollTo({
        top: relativeTop - 20, // 20px padding from top
        behavior: 'smooth',
      })
    }

    // Start trying after a short delay
    const timeoutId = setTimeout(tryScroll, 100)

    return () => clearTimeout(timeoutId)
  }, [scrollTargetLine, getScrollContainer, buildLogs])

  // Scroll handlers for logs
  const handleScrollToTop = () => {
    const scrollContainer = getScrollContainer()
    if (scrollContainer) {
      scrollContainer.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  const handleScrollToBottom = () => {
    const scrollContainer = getScrollContainer()
    if (scrollContainer) {
      scrollContainer.scrollTo({
        top: scrollContainer.scrollHeight,
        behavior: 'smooth',
      })
    }
  }

  // Line click: plain click selects one line; ⌘/Ctrl+click toggles; Shift+click adds a range.
  const handleLineClick = useCallback(
    (lineNumber: number, event: React.MouseEvent<HTMLDivElement>) => {
      const focusLogsPane = () => {
        logsContainerRef.current?.focus({ preventScroll: true })
      }

      if (event.shiftKey) {
        const anchor =
          lineAnchorRef.current ??
          (selectedLogLines.size > 0
            ? Math.min(...selectedLogLines)
            : lineNumber)
        const start = Math.min(anchor, lineNumber)
        const end = Math.max(anchor, lineNumber)
        const next = new Set(selectedLogLines)
        for (let i = start; i <= end; i++) next.add(i)
        setSelectedLogLines(next)
        syncLineSearchUrl(next)
        focusLogsPane()
        return
      }

      if (event.metaKey || event.ctrlKey) {
        const next = new Set(selectedLogLines)
        if (next.has(lineNumber)) next.delete(lineNumber)
        else next.add(lineNumber)
        lineAnchorRef.current = lineNumber
        setSelectedLogLines(next)
        syncLineSearchUrl(next)
        focusLogsPane()
        return
      }

      const isOnlySelectedLine =
        selectedLogLines.size === 1 && selectedLogLines.has(lineNumber)
      lineAnchorRef.current = lineNumber
      const next = isOnlySelectedLine
        ? new Set<number>()
        : new Set([lineNumber])
      setSelectedLogLines(next)
      syncLineSearchUrl(next)
      focusLogsPane()
    },
    [selectedLogLines, syncLineSearchUrl],
  )

  // Extract route params for navigation
  const routeParams = useMemo(() => {
    return {
      projectId,
      [parentResourceParam]: resourceId,
      deploymentId,
    }
  }, [projectId, resourceId, deploymentId, parentResourceParam])

  if (isLoading) {
    return (
      <WizardLayout
        title={t('Deployment details')}
        fullscreen
        useSidebar={false}
        fallbackPath={fallbackPath}
      >
        <div className="flex h-full items-center justify-center">
          <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading deployment...')}
            </p>
          </div>
        </div>
      </WizardLayout>
    )
  }

  if (!deployment) {
    return (
      <WizardLayout
        title={t('Deployment details')}
        fullscreen
        useSidebar={false}
        fallbackPath={fallbackPath}
      >
        <div className="flex h-full items-center justify-center">
          <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
            <p className="mb-4 text-[13px] text-muted-foreground">
              {t('Deployment not found')}
            </p>
          </div>
        </div>
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={
        <>
          <span className="hidden sm:inline">{t('Deployment')}</span>
          <span className="sm:hidden">{t('Deploy')}</span>
          {parentResource?.name && (
            <>
              {' '}
              <span className="text-muted-foreground hidden sm:inline">
                {t('for')}
              </span>{' '}
              <Link
                to={listRoute as unknown}
                params={
                  { projectId, [parentResourceParam]: resourceId } as unknown
                }
                className="link-neutral font-medium"
              >
                {parentResource.name}
              </Link>
            </>
          )}
          <CopyableId
            id={deployment.$id}
            size="sm"
            maxWidth={300}
            className="hidden sm:inline-flex"
          />
          <CopyableId
            id={deployment.$id}
            size="sm"
            maxWidth={200}
            className="sm:hidden"
          />
        </>
      }
      headerActions={
        deployments.length > 0 ? (
          <>
            {/* Previous/Next Navigation - only shown when deployments list is provided */}
            <div className="flex items-center gap-1">
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                disabled={!previousDeployment}
                onClick={() => {
                  if (previousDeployment) {
                    navigate({
                      to: deploymentDetailRoute as unknown,
                      params: {
                        ...routeParams,
                        deploymentId: previousDeployment.$id,
                      } as unknown,
                    })
                  }
                }}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                disabled={!nextDeployment}
                onClick={() => {
                  if (nextDeployment) {
                    navigate({
                      to: deploymentDetailRoute as unknown,
                      params: {
                        ...routeParams,
                        deploymentId: nextDeployment.$id,
                      } as unknown,
                    })
                  }
                }}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          </>
        ) : undefined
      }
      headerBottom={
        <>
          {/* Metadata - Part of Header */}
          <div className="bg-muted/20">
            <div className="px-4 sm:px-6 py-3 sm:py-4">
              <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-6">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 sm:contents">
                  {/* Deployed */}
                  <div className="flex items-center gap-2">
                    <span className="text-[12px] sm:text-[13px] text-muted-foreground">
                      {t('Deployed')}
                    </span>
                    <span className="text-[12px] sm:text-[13px] font-medium text-foreground">
                      <DateTooltip date={deployment.$createdAt} />
                    </span>
                  </div>

                  {deployment.providerCommitAuthor ? (
                    <div className="flex min-w-0 items-center gap-2">
                      <span className="shrink-0 text-[12px] sm:text-[13px] text-muted-foreground">
                        {t('Committer')}
                      </span>
                      {deployment.providerCommitAuthorUrl ? (
                        <a
                          href={deployment.providerCommitAuthorUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="link-neutral min-w-0 truncate text-[12px] sm:text-[13px] font-medium"
                          title={deployment.providerCommitAuthor}
                        >
                          {deployment.providerCommitAuthor}
                        </a>
                      ) : (
                        <span
                          className="min-w-0 truncate text-[12px] sm:text-[13px] font-medium text-foreground"
                          title={deployment.providerCommitAuthor}
                        >
                          {deployment.providerCommitAuthor}
                        </span>
                      )}
                    </div>
                  ) : null}
                </div>

                {/* Build duration and Status - at end */}
                {(deployment.buildDuration != null ||
                  isDeploymentInProgress(deployment.status) ||
                  statusBadge) && (
                  <div className="flex w-full items-center justify-between gap-2 sm:w-auto sm:justify-start sm:gap-3 sm:ms-auto">
                    {(isDeploymentInProgress(deployment.status)
                      ? !isDeploymentTimeout(
                          deployment.status,
                          deployment.$createdAt,
                        )
                      : deployment.buildDuration != null &&
                        deployment.buildDuration > 0 &&
                        !isDeploymentTimeout(
                          deployment.status,
                          deployment.$createdAt,
                        )) && (
                      <div className="flex items-center gap-2">
                        <span className="text-[12px] sm:text-[13px] text-muted-foreground">
                          {t('Duration')}
                        </span>
                        <span className="text-[12px] sm:text-[13px] font-medium text-foreground">
                          {isDeploymentInProgress(deployment.status)
                            ? formatDuration(Math.max(0, elapsedSeconds))
                            : formatDuration(deployment.buildDuration)}
                        </span>
                      </div>
                    )}
                    {(isActiveDeployment || statusBadge) &&
                      (isActiveDeployment ? (
                        <Badge
                          variant="active"
                          className="gap-1.5 text-[12px] font-medium shrink-0 h-6 px-2.5"
                        >
                          <CheckCircle2 className="h-3.5 w-3.5" />
                          {t('Active')}
                        </Badge>
                      ) : (
                        statusBadge && (
                          <Badge
                            variant={statusBadge.badgeVariant}
                            className="gap-1.5 text-[12px] font-medium shrink-0 h-6 px-2.5"
                          >
                            {(() => {
                              const StatusIcon = statusBadge.icon
                              return <StatusIcon className="h-3.5 w-3.5" />
                            })()}
                            {t(statusBadge.label)}
                          </Badge>
                        )
                      ))}
                    {isDeploymentFailed && (
                      <FixWithAgentDropdown
                        prompt={aiFixPrompt}
                        align="end"
                        className="h-6 px-2.5 text-[12px] [&_svg:first-child]:h-3.5 [&_svg:first-child]:w-3.5"
                        hideLabelOnSmallScreens
                      />
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
        </>
      }
      fullscreen
      useSidebar={false}
      constrainWidth={false}
      constrainFooterWidth={false}
      showBackButton={true}
      backButtonLabel="Deployments"
      onBack={navigateToDeploymentsList}
      contentPadding={false}
      contentWrapperClassName="flex min-h-0 flex-1 flex-col overflow-hidden"
      fullscreenContentXClassName="ps-0 pe-0"
      fullscreenInnerClassName="flex min-h-0 flex-1 flex-col"
      onClose={navigateToDeploymentsList}
      contentClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden lg:flex-row"
      footer={
        <>
          <div className="hidden w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-2 lg:flex">
            {/* Left side - Cancel (when building) or Delete button */}
            <div className="flex items-center">
              {deployment &&
              isDeploymentInProgress(deployment.status) &&
              onCancelBuild ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCancelBuildDialogOpen(true)}
                  disabled={cancelBuildMutation.isPending}
                  className="h-9 text-[13px]"
                >
                  <XCircle className="me-1.5 h-4 w-4" />
                  {t('Cancel')}
                </Button>
              ) : (
                <TooltipProvider delayDuration={0}>
                  <TooltipPrimitive.Root>
                    <TooltipTrigger asChild>
                      <span>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => setDeleteDialogOpen(true)}
                          disabled={isActiveDeployment}
                          className="h-9 text-[13px]"
                        >
                          <Trash2 className="me-1.5 h-4 w-4" />
                          {t('Delete')}
                        </Button>
                      </span>
                    </TooltipTrigger>
                    {isActiveDeployment && (
                      <TooltipContent
                        sideOffset={4}
                        className={WIZARD_PORTAL_Z_POPOVER}
                      >
                        <p>
                          {t(
                            'Cannot delete the active deployment. Please activate another deployment first.',
                          )}
                        </p>
                      </TooltipContent>
                    )}
                  </TooltipPrimitive.Root>
                </TooltipProvider>
              )}
            </div>

            {/* Right side - Individual buttons */}
            <div className="flex flex-wrap items-center gap-2 sm:ms-auto">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                  >
                    <Download className="me-1.5 h-4 w-4" />
                    {t('Download')}
                    <ChevronDown className="ms-1.5 h-3.5 w-3.5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                  align="end"
                  className={WIZARD_PORTAL_Z_DROPDOWN}
                >
                  <DropdownMenuItem onClick={handleDownloadSource}>
                    <FileCode className="me-2 h-4 w-4" />
                    {t('Source code')}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    onClick={handleDownloadBuild}
                    disabled={!isDeploymentCompleted(deployment?.status)}
                    title={
                      !isDeploymentCompleted(deployment?.status)
                        ? t(
                            'Build output is available after the deployment has completed.',
                          )
                        : undefined
                    }
                  >
                    <Package className="me-2 h-4 w-4" />
                    {t('Build output')}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
              {onRedeploy && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setRedeployDialogOpen(true)}
                  disabled={redeployMutation.isPending}
                  className="h-9 text-[13px]"
                >
                  <RefreshCw className="me-1.5 h-4 w-4" />
                  {t('Redeploy')}
                </Button>
              )}
              <TooltipProvider delayDuration={0}>
                <TooltipPrimitive.Root>
                  <TooltipTrigger asChild>
                    <span>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                          if (onActivate) {
                            setActivateDialogOpen(true)
                          } else {
                            toast.info(
                              t(
                                'Activate deployment functionality coming soon',
                              ),
                            )
                          }
                        }}
                        disabled={
                          isActiveDeployment ||
                          activateMutation.isPending ||
                          deployment?.status !== 'ready'
                        }
                        className="h-9 text-[13px]"
                      >
                        <Play className="me-1.5 h-4 w-4" />
                        {t('Activate')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  {(isActiveDeployment || deployment?.status !== 'ready') && (
                    <TooltipContent
                      sideOffset={4}
                      className={WIZARD_PORTAL_Z_POPOVER}
                    >
                      <p>
                        {isActiveDeployment
                          ? t('This deployment is already active.')
                          : t('Build must be ready before activating.')}
                      </p>
                    </TooltipContent>
                  )}
                </TooltipPrimitive.Root>
              </TooltipProvider>
            </div>
          </div>

          <div className="w-full lg:hidden">
            <Drawer
              open={deploymentActionsDrawerOpen}
              onOpenChange={setDeploymentActionsDrawerOpen}
            >
              <DrawerTrigger asChild>
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 w-full justify-between gap-2 text-[13px]"
                >
                  <span>{t('Deployment actions')}</span>
                  <ChevronUp className="h-4 w-4 shrink-0 text-muted-foreground" />
                </Button>
              </DrawerTrigger>
              <DrawerContent
                overlayClassName={WIZARD_DRAWER_OVERLAY_Z}
                className={cn(WIZARD_DRAWER_CONTENT_Z, 'max-h-[85dvh]')}
              >
                <DrawerHeader className="!text-start">
                  <DrawerTitle className="text-[15px] font-semibold">
                    {t('Deployment actions')}
                  </DrawerTitle>
                  <DrawerDescription className="sr-only">
                    {t(
                      'Download, redeploy, activate, cancel or delete this deployment.',
                    )}
                  </DrawerDescription>
                </DrawerHeader>
                <div className="flex max-h-[min(65dvh,24rem)] flex-col gap-2 overflow-y-auto px-4 pb-6">
                  {deployment &&
                    isDeploymentInProgress(deployment.status) &&
                    onCancelBuild && (
                      <Button
                        type="button"
                        variant="outline"
                        className="h-10 w-full justify-start text-[13px]"
                        onClick={() => {
                          setDeploymentActionsDrawerOpen(false)
                          openDialogAfterOverlayCloses(() =>
                            setCancelBuildDialogOpen(true),
                          )
                        }}
                        disabled={cancelBuildMutation.isPending}
                      >
                        <XCircle className="me-2 h-4 w-4" />
                        {t('Cancel build')}
                      </Button>
                    )}
                  {!(
                    deployment &&
                    isDeploymentInProgress(deployment.status) &&
                    onCancelBuild
                  ) && (
                    <Button
                      type="button"
                      variant="outline"
                      className="h-10 w-full justify-start text-[13px]"
                      onClick={() => {
                        setDeploymentActionsDrawerOpen(false)
                        openDialogAfterOverlayCloses(() =>
                          setDeleteDialogOpen(true),
                        )
                      }}
                      disabled={isActiveDeployment}
                      title={
                        isActiveDeployment
                          ? t(
                              'Cannot delete the active deployment. Activate another deployment first.',
                            )
                          : undefined
                      }
                    >
                      <Trash2 className="me-2 h-4 w-4" />
                      {t('Delete deployment')}
                    </Button>
                  )}
                  <div className="my-1 h-px bg-border" />
                  <Button
                    type="button"
                    variant="outline"
                    className="h-10 w-full justify-start text-[13px]"
                    onClick={() => {
                      handleDownloadSource()
                      setDeploymentActionsDrawerOpen(false)
                    }}
                  >
                    <FileCode className="me-2 h-4 w-4" />
                    {t('Download source code')}
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    className="h-10 w-full justify-start text-[13px]"
                    onClick={() => {
                      handleDownloadBuild()
                      setDeploymentActionsDrawerOpen(false)
                    }}
                    disabled={!isDeploymentCompleted(deployment?.status)}
                    title={
                      !isDeploymentCompleted(deployment?.status)
                        ? t(
                            'Build output is available after the deployment has completed.',
                          )
                        : undefined
                    }
                  >
                    <Package className="me-2 h-4 w-4" />
                    {t('Download build output')}
                  </Button>
                  {onRedeploy && (
                    <Button
                      type="button"
                      variant="outline"
                      className="h-10 w-full justify-start text-[13px]"
                      onClick={() => {
                        setDeploymentActionsDrawerOpen(false)
                        setRedeployDialogOpen(true)
                      }}
                      disabled={redeployMutation.isPending}
                    >
                      <RefreshCw className="me-2 h-4 w-4" />
                      {t('Redeploy')}
                    </Button>
                  )}
                  <Button
                    type="button"
                    variant="outline"
                    className="h-10 w-full justify-start text-[13px]"
                    onClick={() => {
                      setDeploymentActionsDrawerOpen(false)
                      if (onActivate) {
                        setActivateDialogOpen(true)
                      } else {
                        toast.info(
                          t('Activate deployment functionality coming soon'),
                        )
                      }
                    }}
                    disabled={
                      isActiveDeployment ||
                      activateMutation.isPending ||
                      deployment?.status !== 'ready'
                    }
                    title={
                      isActiveDeployment
                        ? t('This deployment is already active.')
                        : activateMutation.isPending
                          ? undefined
                          : deployment?.status !== 'ready'
                            ? t('Build must be ready before activating.')
                            : undefined
                    }
                  >
                    <Play className="me-2 h-4 w-4" />
                    {t('Activate')}
                  </Button>
                </div>
              </DrawerContent>
            </Drawer>
          </div>
        </>
      }
    >
      <>
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden lg:flex-row">
          <div className="flex min-h-0 min-w-0 flex-1 flex-col lg:border-e lg:border-border">
            <div className="min-w-0 shrink-0 border-b border-border">
              <TooltipProvider>
                <div className="flex min-w-0 items-center gap-2 py-3 ps-6 pe-4 sm:pe-5">
                  <div className="relative min-w-0 flex-1">
                    <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      placeholder={t('Search logs...')}
                      value={logsSearch}
                      onChange={(e) => setLogsSearch(e.target.value)}
                      className="h-9 w-full min-w-0 ps-9 text-[13px]"
                    />
                  </div>
                  <TooltipPrimitive.Root>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={handleDownloadLogs}
                        disabled={!buildLogs}
                        className="h-9 w-9 shrink-0 p-0"
                      >
                        <Download className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p>{t('Download logs')}</p>
                    </TooltipContent>
                  </TooltipPrimitive.Root>
                  <TooltipPrimitive.Root>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={handleCopyLogs}
                        disabled={!buildLogs}
                        className="h-9 w-9 shrink-0 p-0"
                      >
                        <Copy className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p>
                        {selectedLogLines.size > 0
                          ? `Copy ${selectedLogLines.size} selected line${selectedLogLines.size === 1 ? '' : 's'} (⌘C / Ctrl+C)`
                          : t('Copy logs')}
                      </p>
                    </TooltipContent>
                  </TooltipPrimitive.Root>
                </div>
              </TooltipProvider>
            </div>
            <div
              ref={logsContainerRef}
              tabIndex={-1}
              className="flex min-h-0 min-w-0 w-full flex-1 flex-col overflow-y-auto overflow-x-auto ps-6 pe-4 pb-44 sm:pe-5 outline-none"
            >
              <div className="min-h-full min-w-0">
                <BuildLogsView
                  buildLogs={buildLogs}
                  searchTerm={logsSearch}
                  selectedLines={selectedLogLines}
                  onLineClick={handleLineClick}
                  lineRefs={lineRefs}
                  emptyMessage={t('No build logs available.')}
                  lineHorizontalPaddingClass="ps-0 pe-0"
                />
              </div>
            </div>
          </div>

          <aside className="hidden min-h-0 w-[min(100%,20rem)] shrink-0 flex-col overflow-y-auto bg-muted/10 px-6 py-3 lg:flex xl:w-[min(100%,22rem)]">
            {deploymentDetailSidebar}
          </aside>
        </div>

        {/* Scroll controls: fixed over logs; inset from right on lg+ to clear the side panel */}
        {buildLogs && (
          <div className="pointer-events-none fixed bottom-24 end-8 z-[101] lg:end-[calc(20rem+1.25rem)] xl:end-[calc(22rem+1.25rem)]">
            <div className="pointer-events-auto flex flex-col gap-2">
              <TooltipProvider>
                <TooltipPrimitive.Root>
                  <TooltipTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={handleScrollToTop}
                      disabled={isAtTop}
                      className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                    >
                      <ArrowUp className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="left">
                    <p>{t('Scroll to top')}</p>
                  </TooltipContent>
                </TooltipPrimitive.Root>
                <TooltipPrimitive.Root>
                  <TooltipTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={handleScrollToBottom}
                      disabled={isAtBottom}
                      className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                    >
                      <ArrowDown className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="left">
                    <p>{t('Scroll to bottom')}</p>
                  </TooltipContent>
                </TooltipPrimitive.Root>
              </TooltipProvider>
            </div>
          </div>
        )}
      </>

      {/* Cancel build confirmation */}
      <Dialog
        open={cancelBuildDialogOpen}
        onOpenChange={setCancelBuildDialogOpen}
      >
        <DialogContent
          overlayClassName={WIZARD_DIALOG_OVERLAY_Z}
          className={cn('sm:max-w-md p-0', WIZARD_DIALOG_CONTENT_Z)}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Cancel build')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Stop the current deployment? You can deploy again later.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            {deployment && (
              <DeploymentInfo deployment={deployment} showStatus={true} />
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setCancelBuildDialogOpen(false)}
              className="h-9 text-[13px]"
            >
              {t('Keep building')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => cancelBuildMutation.mutate()}
              disabled={cancelBuildMutation.isPending}
              className="h-9 text-[13px]"
            >
              {t('Cancel')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent
          overlayClassName={WIZARD_DIALOG_OVERLAY_Z}
          className={cn('sm:max-w-md p-0', WIZARD_DIALOG_CONTENT_Z)}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete deployment')}</DialogTitle>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <DialogDescription className="text-[13px] mb-4">
              {t(
                'Are you sure you want to delete this deployment? This action cannot be undone.',
              )}
            </DialogDescription>
            <DeploymentInfo deployment={deployment} showStatus={true} />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              className="h-9 text-[13px]"
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteMutation.mutate()}
              disabled={deleteMutation.isPending}
              className="h-9 text-[13px]"
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Redeploy Confirmation Dialog */}
      {onRedeploy && (
        <Dialog open={redeployDialogOpen} onOpenChange={setRedeployDialogOpen}>
          <DialogContent
            overlayClassName={WIZARD_DIALOG_OVERLAY_Z}
            className={cn('sm:max-w-md p-0', WIZARD_DIALOG_CONTENT_Z)}
          >
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>{t('Redeploy deployment')}</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                {t(
                  isSiteDeployment
                    ? "This will create a new build for this deployment using the current site configuration. The original deployment's code will be preserved and used for the new build."
                    : "This will create a new build for this deployment using the current function configuration. The original deployment's code will be preserved and used for the new build.",
                )}
              </DialogDescription>
              <DeploymentInfo deployment={deployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setRedeployDialogOpen(false)}
                disabled={redeployMutation.isPending}
                className="h-9 text-[13px]"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="default"
                onClick={() => redeployMutation.mutate()}
                disabled={redeployMutation.isPending}
                className="h-9 text-[13px]"
              >
                {t('Redeploy')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      {/* Activate Confirmation Dialog */}
      {onActivate && (
        <Dialog open={activateDialogOpen} onOpenChange={setActivateDialogOpen}>
          <DialogContent
            overlayClassName={WIZARD_DIALOG_OVERLAY_Z}
            className={cn('sm:max-w-md p-0', WIZARD_DIALOG_CONTENT_Z)}
          >
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>{t('Activate deployment')}</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                {t(
                  'This will switch the active deployment to this one. All traffic will be routed to this deployment once activated.',
                )}
              </DialogDescription>
              <DeploymentInfo deployment={deployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setActivateDialogOpen(false)}
                disabled={activateMutation.isPending}
                className="h-9 text-[13px]"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="default"
                onClick={() => activateMutation.mutate()}
                disabled={activateMutation.isPending}
                className="h-9 text-[13px]"
              >
                {t('Activate')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </WizardLayout>
  )
}
