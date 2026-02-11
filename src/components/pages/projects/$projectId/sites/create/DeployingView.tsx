/**
 * Deploying View Component
 *
 * Shows real-time deployment progress with logs. The same view evolves when
 * status changes to ready or failed: outcome appears with smooth transitions,
 * and on success the completion content (preview + next steps) appears inline.
 */

import { useState, useEffect, useRef, useMemo, useCallback } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  TooltipProvider,
  TooltipTrigger,
  TooltipContent,
} from '@/components/ui/tooltip'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { BuildLogsView } from '@/components/global/shared/BuildLogsView'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { getDeploymentStatusBadge } from '@/lib/utils/deployment-status'
import { sdk } from '@/lib/appwrite/sdk'
import {
  CircleDashed,
  Search,
  ArrowUp,
  ArrowDown,
  Copy,
  Download,
  ExternalLink,
  Globe,
  GitBranch,
  Share2,
  Smartphone,
  Loader2,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  useProjectSite,
  useSiteDeployment,
  useRepository,
  useSiteDomains,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'

const SCREENSHOTS_BUCKET_ID = 'screenshots'

function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1000
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${(bytes / Math.pow(k, i)).toFixed(2)} ${sizes[i]}`
}

interface DeployingViewProps {
  siteId?: string
  deploymentId?: string
}

export function DeployingView({ siteId, deploymentId }: DeployingViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { theme, resolvedTheme } = useTheme()
  const { formData, frameworks, resetFormData } = useWizard()
  const logsContainerRef = useRef<HTMLDivElement>(null)
  /** Set on first scroll; until then we auto-scroll so initial load follows tail. */
  const hasUserScrolledRef = useRef(false)
  const [logsSearch, setLogsSearch] = useState('')
  const [isAtTop, setIsAtTop] = useState(true)
  const [isAtBottom, setIsAtBottom] = useState(false)
  const [qrDialogOpen, setQrDialogOpen] = useState(false)
  const [previewImageLoaded, setPreviewImageLoaded] = useState(false)

  // Use provided IDs or fall back to form data
  const actualSiteId = siteId || formData.createdSiteId
  const actualDeploymentId = deploymentId || formData.createdDeploymentId

  // Fetch site details
  const { data: site } = useProjectSite(projectId, actualSiteId)

  // Fetch deployment details (updated via realtime in RealtimeProvider)
  const { data: deployment } = useSiteDeployment(
    projectId,
    actualSiteId,
    actualDeploymentId,
  )

  // Fetch repository if connected
  const { data: repository } = useRepository(
    projectId,
    site?.installationId || null,
    site?.providerRepositoryId || null,
  )

  // Fetch site domains (for completion: primary domain / site URL)
  const { rules: domains } = useSiteDomains(projectId, actualSiteId, 0, 10)

  // Local state for status
  const [status, setStatus] = useState<string>('building')
  const buildLogs = deployment?.buildLogs ?? ''

  // Update status from deployment
  useEffect(() => {
    if (deployment) {
      setStatus(deployment.status)
    }
  }, [deployment])

  // Auto-scroll when new logs arrive if user hasn't scrolled or is at bottom (same as scroll-to-bottom control)
  useEffect(() => {
    const shouldFollow = !hasUserScrolledRef.current || isAtBottom
    if (!shouldFollow || !logsContainerRef.current) return
    const el = logsContainerRef.current
    el.scrollTop = el.scrollHeight
  }, [buildLogs, isAtBottom])

  // Track scroll position for scroll-to-top/bottom buttons (controls auto-scroll: follow when at bottom)
  const updateScrollPosition = useCallback(() => {
    const el = logsContainerRef.current
    if (!el) return
    hasUserScrolledRef.current = true
    const { scrollTop, scrollHeight, clientHeight } = el
    const threshold = 10
    setIsAtTop(scrollTop <= threshold)
    setIsAtBottom(scrollTop + clientHeight >= scrollHeight - threshold)
  }, [])

  useEffect(() => {
    const el = logsContainerRef.current
    if (!el) return
    updateScrollPosition()
    el.addEventListener('scroll', updateScrollPosition)
    window.addEventListener('resize', updateScrollPosition)
    return () => {
      el.removeEventListener('scroll', updateScrollPosition)
      window.removeEventListener('resize', updateScrollPosition)
    }
  }, [updateScrollPosition, buildLogs])

  const handleScrollToTop = useCallback(() => {
    logsContainerRef.current?.scrollTo({ top: 0, behavior: 'smooth' })
  }, [])

  const handleScrollToBottom = useCallback(() => {
    const el = logsContainerRef.current
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
  }, [])

  const handleCopyLogs = useCallback(async () => {
    if (!buildLogs) {
      toast.error('No logs to copy')
      return
    }
    try {
      await navigator.clipboard.writeText(buildLogs)
      toast.success('Logs copied to clipboard')
    } catch {
      toast.error('Failed to copy logs')
    }
  }, [buildLogs])

  const handleDownloadLogs = useCallback(() => {
    if (!buildLogs) {
      toast.error('No logs to download')
      return
    }
    const blob = new Blob([buildLogs], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `build-logs-${actualDeploymentId || 'deployment'}.txt`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    toast.success('Logs downloaded')
  }, [buildLogs, actualDeploymentId])

  const handleGoToDashboard = () => {
    if (status === 'ready') {
      resetFormData()
    }
    if (actualSiteId) {
      navigate({
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: actualSiteId },
      })
    } else {
      navigate({
        to: '/projects/$projectId/sites',
        params: { projectId: projectId! },
      })
    }
  }

  // Completion content (when status === 'ready'): screenshot URL and primary domain
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return (
      resolvedTheme === 'dark' ||
      (resolvedTheme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches) ||
      theme === 'dark'
    )
  }, [theme, resolvedTheme])

  const screenshotUrl = useMemo(() => {
    if (!deployment) return null
    const screenshotId = isDark
      ? (deployment as unknown).screenshotDark
      : (deployment as unknown).screenshotLight
    if (!screenshotId) return null
    return sdk.forConsole.storage.getFilePreview({
      bucketId: SCREENSHOTS_BUCKET_ID,
      fileId: screenshotId,
      width: 1280,
      height: 720,
    })
  }, [deployment, isDark])

  const primaryDomain = useMemo(() => {
    if (domains.length > 0) return domains[0].domain
    return null
  }, [domains])
  const siteUrl = primaryDomain ? `https://${primaryDomain}` : null

  // QR code image URL from console avatars API (for "View on mobile" dialog)
  const qrImageUrl = useMemo(() => {
    if (!siteUrl) return null
    return sdk.forConsole.avatars.getQR({ text: siteUrl, size: 256 })
  }, [siteUrl])

  // Reset preview loaded state when screenshot URL changes
  useEffect(() => {
    setPreviewImageLoaded(false)
  }, [screenshotUrl])

  // Shared build logs content (search, viewer, scroll buttons)
  const buildLogsSectionContent = (
    <>
      <div className="border-t border-border px-4 sm:px-6 py-3">
        <TooltipProvider>
          <div className="flex items-center gap-2">
            <div className="relative flex-1 min-w-0">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search logs..."
                value={logsSearch}
                onChange={(e) => setLogsSearch(e.target.value)}
                className="pl-9 h-9 text-[13px]"
              />
            </div>
            <TooltipPrimitive.Root>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleDownloadLogs}
                  disabled={!buildLogs}
                  className="h-9 w-9 p-0 shrink-0"
                >
                  <Download className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Download logs</p>
              </TooltipContent>
            </TooltipPrimitive.Root>
            <TooltipPrimitive.Root>
              <TooltipTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleCopyLogs}
                  disabled={!buildLogs}
                  className="h-9 w-9 p-0 shrink-0"
                >
                  <Copy className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Copy logs</p>
              </TooltipContent>
            </TooltipPrimitive.Root>
          </div>
        </TooltipProvider>
      </div>
      <div className="border-t border-border" />
      <div className="relative">
        <div
          ref={logsContainerRef}
          className="h-[400px] overflow-y-auto overflow-x-auto"
        >
          <BuildLogsView
            buildLogs={buildLogs}
            searchTerm={logsSearch}
            highlightLineOnHover
            emptyMessage={
              <div className="flex items-center gap-2">
                <CircleDashed className="h-3.5 w-3.5 shrink-0" />
                Waiting for build logs...
              </div>
            }
          />
        </div>
        {buildLogs && (
          <div className="absolute bottom-3 right-3 flex flex-col gap-2 z-10">
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
                  <p>Scroll to top</p>
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
                  <p>Scroll to bottom</p>
                </TooltipContent>
              </TooltipPrimitive.Root>
            </TooltipProvider>
          </div>
        )}
      </div>
    </>
  )

  const frameworkInfo = site
    ? frameworks.find((f) => f.key === site.framework)
    : null

  // Same status badge as deployment details wizard
  const statusBadge = deployment
    ? getDeploymentStatusBadge(deployment.status, deployment.$createdAt)
    : null

  // Elapsed seconds while building (updates every second so duration ticks live)
  const [elapsedSeconds, setElapsedSeconds] = useState(0)
  useEffect(() => {
    if (!deployment?.$createdAt) return
    const tick = () => {
      const created = new Date(deployment.$createdAt).getTime()
      setElapsedSeconds(Math.floor((Date.now() - created) / 1000))
    }
    tick()
    const interval = setInterval(tick, 1000)
    return () => clearInterval(interval)
  }, [deployment?.$createdAt])

  // Duration to show on build logs card: for any status – buildDuration when done, else elapsed (including 0s)
  const buildDurationDisplay = useMemo(() => {
    if (!deployment?.$createdAt) return null
    const isDone =
      deployment.status === 'ready' || deployment.status === 'failed'
    if (
      isDone &&
      deployment.buildDuration != null &&
      deployment.buildDuration >= 0
    ) {
      return formatDuration(deployment.buildDuration)
    }
    const elapsed =
      deployment.status === 'building' ||
      deployment.status === 'processing' ||
      deployment.status === 'waiting'
        ? elapsedSeconds
        : Math.floor(
            (Date.now() - new Date(deployment.$createdAt).getTime()) / 1000,
          )
    return formatDuration(Math.max(0, elapsed))
  }, [deployment, elapsedSeconds])

  const sidebarContent =
    site || deployment ? (
      <div className="space-y-4">
        {/* QR code dialog (used when status === 'ready') */}
        {status === 'ready' && (
          <Dialog open={qrDialogOpen} onOpenChange={setQrDialogOpen}>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-left">
                <DialogTitle>View on mobile</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Scan this QR code to open your site on a mobile device
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 py-6 flex items-center justify-center">
                {qrImageUrl && (
                  <div className="p-4 bg-white rounded-lg">
                    <img
                      src={qrImageUrl}
                      alt="QR code to open site on mobile"
                      className="h-48 w-48 rounded"
                    />
                  </div>
                )}
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
                <Button
                  variant="outline"
                  onClick={() => setQrDialogOpen(false)}
                >
                  Close
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        )}

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          {/* Header: icon + site name + status */}
          <div className="px-5 py-4">
            <div className="flex items-start gap-3">
              {site && (
                <div className="relative shrink-0">
                  <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
                    <FrameworkIcon framework={site.framework} size="md" />
                  </div>
                  <div className="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-background ring-2 ring-background">
                    <GitHubIcon className="h-3 w-3 text-muted-foreground" />
                  </div>
                </div>
              )}
              <div className="flex-1 min-w-0">
                {site && (
                  <>
                    <h3 className="text-[15px] font-semibold text-foreground truncate">
                      {site.name}
                    </h3>
                    <CopyableId id={site.$id} size="xs" className="mt-0.5" />
                  </>
                )}
              </div>
              {statusBadge && (
                <Badge
                  variant={statusBadge.badgeVariant}
                  className="gap-1.5 text-[11px] font-medium shrink-0 h-6 px-2.5"
                >
                  {(() => {
                    const StatusIcon = statusBadge.icon
                    return <StatusIcon className="h-3.5 w-3.5" />
                  })()}
                  {statusBadge.label}
                </Badge>
              )}
            </div>
          </div>

          {/* Metadata: key-value rows, tech style */}
          <div className="border-t border-border px-5 py-3.5 bg-muted/10">
            <dl className="space-y-2.5">
              {frameworkInfo && (
                <div className="flex justify-between gap-3 items-center">
                  <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground shrink-0">
                    Framework
                  </dt>
                  <dd className="text-[12px] font-medium text-foreground truncate text-right">
                    {frameworkInfo.name}
                  </dd>
                </div>
              )}
              {repository && (
                <div className="flex justify-between gap-3 items-center">
                  <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground shrink-0">
                    Source
                  </dt>
                  <dd className="text-[12px] font-mono text-foreground truncate text-right">
                    {repository.organization}/{repository.name}
                  </dd>
                </div>
              )}
              {site?.providerBranch && (
                <div className="flex justify-between gap-3 items-center">
                  <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground shrink-0">
                    Branch
                  </dt>
                  <dd className="text-[12px] font-mono text-foreground truncate text-right">
                    {site.providerBranch}
                  </dd>
                </div>
              )}
              {deployment && (
                <>
                  <div className="flex justify-between gap-3 items-center">
                    <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground shrink-0">
                      Deployed
                    </dt>
                    <dd className="text-[12px] font-medium text-foreground text-right">
                      <DateTooltip date={deployment.$createdAt} />
                    </dd>
                  </div>
                  {(deployment.buildSize ?? 0) + (deployment.sourceSize ?? 0) >
                    0 && (
                    <div className="flex justify-between gap-3 items-center">
                      <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground shrink-0">
                        Size
                      </dt>
                      <dd className="text-[12px] font-medium text-foreground text-right">
                        {formatSize(
                          (deployment.buildSize ?? 0) +
                            (deployment.sourceSize ?? 0),
                        )}
                      </dd>
                    </div>
                  )}
                </>
              )}
            </dl>
          </div>
        </div>
      </div>
    ) : null

  return (
    <WizardLayout
      title="Create site"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <Button
          variant={status === 'ready' ? 'default' : 'outline'}
          onClick={handleGoToDashboard}
        >
          Go to dashboard
        </Button>
      }
    >
      <div className="space-y-4">
        {/* Build logs – only while building; hidden when ready */}
        {status !== 'ready' && (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4 flex items-center justify-between gap-3">
              <h3 className="text-[15px] font-semibold text-foreground">
                Build logs
              </h3>
              {buildDurationDisplay && (
                <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">
                  Duration:{' '}
                  <span className="font-medium text-foreground">
                    {buildDurationDisplay}
                  </span>
                </span>
              )}
            </div>
            {buildLogsSectionContent}
          </div>
        )}

        {/* Completion content – appears when ready, same view evolves */}
        {status === 'ready' && site && (
          <div
            className="space-y-4 transition-all duration-300 ease-out animate-in fade-in-0 slide-in-from-bottom-4"
            style={{
              animationDuration: '400ms',
              animationFillMode: 'backwards',
            }}
          >
            {/* Site preview card */}
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              {screenshotUrl ? (
                <div className="aspect-[21/9] w-full relative overflow-hidden bg-muted">
                  {!previewImageLoaded && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20">
                      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                      <p className="text-[13px] font-medium text-muted-foreground">
                        Loading preview…
                      </p>
                    </div>
                  )}
                  <img
                    src={screenshotUrl}
                    alt={`${site.name} preview`}
                    className="h-full w-full object-cover"
                    onLoad={() => setPreviewImageLoaded(true)}
                  />
                </div>
              ) : (
                <div className="aspect-[21/9] w-full flex flex-col items-center justify-center gap-3 bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20">
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                  <p className="text-[13px] font-medium text-muted-foreground">
                    Generating preview…
                  </p>
                  <p className="text-[12px] text-muted-foreground/80">
                    Screenshot may take a few moments after build completes
                  </p>
                  <FrameworkIcon
                    framework={site.framework}
                    size="lg"
                    className="mt-2 opacity-50"
                  />
                </div>
              )}
              <div className="p-6">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex items-start gap-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <FrameworkIcon framework={site.framework} size="md" />
                    </div>
                    <div>
                      <h3 className="text-[16px] font-semibold text-foreground">
                        {site.name}
                      </h3>
                      <CopyableId id={site.$id} size="xs" />
                      {siteUrl && (
                        <a
                          href={siteUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="mt-2 flex items-center gap-1 text-[12px] text-primary hover:underline"
                        >
                          <Globe className="h-3.5 w-3.5" />
                          {primaryDomain}
                        </a>
                      )}
                    </div>
                  </div>
                  {siteUrl && (
                    <Button asChild>
                      <a
                        href={siteUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <ExternalLink className="mr-1.5 h-4 w-4" />
                        Visit site
                      </a>
                    </Button>
                  )}
                </div>
              </div>
            </div>

            {/* Next steps – standard settings card with action row */}
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Next steps
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  Configure your site or share it with others
                </p>
              </div>
              <div className="border-t border-border" />
              <div className="grid grid-cols-1 sm:grid-cols-2 divide-x divide-y divide-border">
                {site && !site.installationId && (
                  <Link
                    to="/projects/$projectId/sites/$siteId/settings"
                    params={{ projectId: projectId!, siteId: actualSiteId! }}
                    className="flex items-center gap-4 px-6 py-4 hover:bg-muted/20 transition-colors cursor-pointer"
                  >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <GitBranch className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-[13px] font-medium text-foreground">
                        Add repository
                      </p>
                      <p className="text-[11px] text-muted-foreground mt-0.5">
                        Connect Git for automatic deployments
                      </p>
                    </div>
                  </Link>
                )}
                <Link
                  to="/projects/$projectId/sites/$siteId/domains"
                  params={{ projectId: projectId!, siteId: actualSiteId! }}
                  className="flex items-center gap-4 px-6 py-4 hover:bg-muted/20 transition-colors cursor-pointer"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Globe className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      Add custom domain
                    </p>
                    <p className="text-[11px] text-muted-foreground mt-0.5">
                      Use your own domain name
                    </p>
                  </div>
                </Link>
                <button
                  type="button"
                  onClick={() => {
                    if (siteUrl) {
                      navigator.clipboard.writeText(siteUrl)
                      toast.success('URL copied to clipboard')
                    }
                  }}
                  className="flex items-center gap-4 px-6 py-4 hover:bg-muted/20 transition-colors cursor-pointer text-left w-full"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Share2 className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      Copy site URL
                    </p>
                    <p className="text-[11px] text-muted-foreground mt-0.5">
                      Copy URL to clipboard
                    </p>
                  </div>
                </button>
                <button
                  type="button"
                  onClick={() => setQrDialogOpen(true)}
                  className="flex items-center gap-4 px-6 py-4 hover:bg-muted/20 transition-colors cursor-pointer text-left w-full"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Smartphone className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      Open on mobile
                    </p>
                    <p className="text-[11px] text-muted-foreground mt-0.5">
                      Scan QR code
                    </p>
                  </div>
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </WizardLayout>
  )
}
