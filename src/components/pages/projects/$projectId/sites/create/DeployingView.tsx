/**
 * Deploying View Component
 *
 * Shows real-time deployment progress with logs.
 * Subscribes to realtime updates and navigates to finish screen when complete.
 */

import { useState, useEffect, useRef, useMemo, useCallback } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import {
  TooltipProvider,
  TooltipTrigger,
  TooltipContent,
} from '@/components/ui/tooltip'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { BuildLogsView } from '@/components/global/shared/BuildLogsView'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { getDeploymentStatusBadge } from '@/lib/utils/deployment-status'
import { Loader2, CheckCircle2, XCircle, Search, ArrowUp, ArrowDown, Copy, Download } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useProjectSite,
  useSiteDeployment,
  useRepository,
  siteQueryOptions,
  siteDeploymentQueryOptions,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'

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
  const queryClient = useQueryClient()
  const { formData, frameworks } = useWizard()
  const logsContainerRef = useRef<HTMLDivElement>(null)
  const [logsSearch, setLogsSearch] = useState('')
  const [isAtTop, setIsAtTop] = useState(true)
  const [isAtBottom, setIsAtBottom] = useState(false)

  // Use provided IDs or fall back to form data
  const actualSiteId = siteId || formData.createdSiteId
  const actualDeploymentId = deploymentId || formData.createdDeploymentId

  // Fetch site details
  const { data: site } = useProjectSite(projectId, actualSiteId)

  // Fetch deployment details with polling
  const { data: deployment, refetch: refetchDeployment } = useSiteDeployment(
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

  // Local state for status
  const [status, setStatus] = useState<string>('building')
  const buildLogs = deployment?.buildLogs ?? ''

  // Poll for deployment status
  useEffect(() => {
    if (!actualDeploymentId) return

    const interval = setInterval(() => {
      refetchDeployment()
    }, 3000)

    return () => clearInterval(interval)
  }, [actualDeploymentId, refetchDeployment])

  // Update status from deployment
  useEffect(() => {
    if (deployment) {
      setStatus(deployment.status)
    }
  }, [deployment])

  // Auto-scroll logs when build output updates
  useEffect(() => {
    if (logsContainerRef.current) {
      logsContainerRef.current.scrollTop = logsContainerRef.current.scrollHeight
    }
  }, [buildLogs])

  // Track scroll position for scroll-to-top/bottom buttons
  const updateScrollPosition = useCallback(() => {
    const el = logsContainerRef.current
    if (!el) return
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
    } catch (error) {
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

  // Navigate to finish screen when deployment is ready
  useEffect(() => {
    if (status === 'ready' && actualSiteId && actualDeploymentId) {
      // Wait a bit for screenshots to be generated
      const timer = setTimeout(() => {
        navigate({
          to: '/projects/$projectId/sites/create/finish',
          params: { projectId: projectId! },
          search: { siteId: actualSiteId, deploymentId: actualDeploymentId },
        })
      }, 2000)

      return () => clearTimeout(timer)
    }
  }, [status, actualSiteId, actualDeploymentId, navigate, projectId])

  const handleGoToDashboard = () => {
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
    const isDone = deployment.status === 'ready' || deployment.status === 'failed'
    if (isDone && deployment.buildDuration != null && deployment.buildDuration >= 0) {
      return formatDuration(deployment.buildDuration)
    }
    const elapsed = (deployment.status === 'building' || deployment.status === 'processing' || deployment.status === 'waiting')
      ? elapsedSeconds
      : Math.floor((Date.now() - new Date(deployment.$createdAt).getTime()) / 1000)
    return formatDuration(Math.max(0, elapsed))
  }, [deployment, elapsedSeconds])

  const sidebarContent = (site || deployment) ? (
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
              {statusBadge.icon === Loader2 ? (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              ) : (
                (() => {
                  const StatusIcon = statusBadge.icon
                  return <StatusIcon className="h-3.5 w-3.5" />
                })()
              )}
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
              {((deployment.buildSize ?? 0) + (deployment.sourceSize ?? 0)) > 0 && (
                <div className="flex justify-between gap-3 items-center">
                  <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground shrink-0">
                    Size
                  </dt>
                  <dd className="text-[12px] font-medium text-foreground text-right">
                    {formatSize(
                      (deployment.buildSize ?? 0) + (deployment.sourceSize ?? 0),
                    )}
                  </dd>
                </div>
              )}
            </>
          )}
        </dl>
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
        <Button variant="outline" onClick={handleGoToDashboard}>
          Go to dashboard
        </Button>
      }
    >
      {/* Deployment logs – same component as deployment details (line numbers + ANSI highlighting) */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4 flex items-center justify-between gap-3">
          <h3 className="text-[15px] font-semibold text-foreground">
            Build logs
          </h3>
          {buildDurationDisplay && (
            <span className="text-[12px] sm:text-[13px] text-muted-foreground shrink-0">
              Duration: <span className="font-medium text-foreground">{buildDurationDisplay}</span>
            </span>
          )}
        </div>
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
                  <Loader2 className="h-3.5 w-3.5 animate-spin shrink-0" />
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
      </div>

      {/* Deployment outcome – unified structure for ready and failed */}
      {(status === 'ready' || status === 'failed') && (
        <div
          className={
            status === 'ready'
              ? 'rounded-xl border border-green-500/30 bg-green-500/10 p-4'
              : 'rounded-xl border border-destructive/30 bg-destructive/10 p-4'
          }
        >
          <div className="flex items-center gap-3">
            {status === 'ready' ? (
              <CheckCircle2 className="h-5 w-5 text-green-500 shrink-0" />
            ) : (
              <XCircle className="h-5 w-5 text-destructive shrink-0" />
            )}
            <div className="flex-1 min-w-0">
              <p
                className={
                  status === 'ready'
                    ? 'text-[13px] font-medium text-green-600 dark:text-green-400'
                    : 'text-[13px] font-medium text-destructive'
                }
              >
                {status === 'ready'
                  ? 'Deployment successful'
                  : 'Deployment failed'}
              </p>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                {status === 'ready'
                  ? 'Redirecting to completion screen...'
                  : 'Check the build logs for more details'}
              </p>
              {status === 'ready' && deployment?.buildDuration != null && deployment.buildDuration >= 0 && (
                <p className="text-[11px] text-muted-foreground mt-1.5">
                  Build completed in {formatDuration(deployment.buildDuration)}
                </p>
              )}
            </div>
          </div>
        </div>
      )}
    </WizardLayout>
  )
}
