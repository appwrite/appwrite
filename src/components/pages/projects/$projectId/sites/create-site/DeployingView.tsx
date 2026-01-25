/**
 * Deploying View Component
 *
 * Shows real-time deployment progress with logs.
 * Subscribes to realtime updates and navigates to finish screen when complete.
 */

import { useState, useEffect, useRef } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Loader2, CheckCircle2, XCircle, ExternalLink } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useProjectSite,
  useSiteDeployment,
  useRepository,
  siteQueryOptions,
  siteDeploymentQueryOptions,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'

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

  // Local state for logs
  const [logs, setLogs] = useState<string[]>([])
  const [status, setStatus] = useState<string>('building')

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

      // Parse build logs if available
      if (deployment.buildLogs) {
        setLogs(deployment.buildLogs.split('\n').filter((l) => l.trim()))
      }
    }
  }, [deployment])

  // Auto-scroll logs
  useEffect(() => {
    if (logsContainerRef.current) {
      logsContainerRef.current.scrollTop = logsContainerRef.current.scrollHeight
    }
  }, [logs])

  // Navigate to finish screen when deployment is ready
  useEffect(() => {
    if (status === 'ready' && actualSiteId && actualDeploymentId) {
      // Wait a bit for screenshots to be generated
      const timer = setTimeout(() => {
        navigate({
          to: '/projects/$projectId/sites/create-site/finish',
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

  const getStatusIcon = () => {
    switch (status) {
      case 'ready':
        return <CheckCircle2 className="h-5 w-5 text-green-500" />
      case 'failed':
        return <XCircle className="h-5 w-5 text-destructive" />
      default:
        return <Loader2 className="h-5 w-5 animate-spin text-primary" />
    }
  }

  const getStatusText = () => {
    switch (status) {
      case 'building':
        return 'Building...'
      case 'ready':
        return 'Deployment successful'
      case 'failed':
        return 'Deployment failed'
      default:
        return 'Deploying...'
    }
  }

  const frameworkInfo = site
    ? frameworks.find((f) => f.key === site.framework)
    : null

  const sidebarContent = (
    <div className="space-y-4">
      {/* Framework info */}
      {site && frameworkInfo && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-3 mb-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <FrameworkIcon framework={site.framework} size="md" />
            </div>
            <div>
              <h3 className="text-[13px] font-semibold text-foreground">
                {frameworkInfo.name}
              </h3>
              <p className="text-[11px] text-muted-foreground">Framework</p>
            </div>
          </div>
        </div>
      )}

      {/* Repository info */}
      {repository && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <p className="text-[12px] text-muted-foreground mb-1">Repository</p>
          <p className="text-[13px] font-medium text-foreground truncate">
            {repository.organization}/{repository.name}
          </p>
          {site?.providerBranch && (
            <p className="text-[11px] text-muted-foreground mt-1">
              Branch: {site.providerBranch}
            </p>
          )}
        </div>
      )}
    </div>
  )

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
      {/* Site info card */}
      {site && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <FrameworkIcon framework={site.framework} size="md" />
            </div>
            <div className="flex-1 min-w-0">
              <h3 className="text-[15px] font-semibold text-foreground">
                {site.name}
              </h3>
              <CopyableId id={site.$id} size="xs" />
            </div>
            <div className="flex items-center gap-2">
              {getStatusIcon()}
              <span className="text-[13px] font-medium text-foreground">
                {getStatusText()}
              </span>
            </div>
          </div>
        </div>
      )}

      {/* Deployment logs */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4 flex items-center justify-between">
          <h3 className="text-[15px] font-semibold text-foreground">
            Build logs
          </h3>
          {status === 'building' && (
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          )}
        </div>
        <div className="border-t border-border" />
        <div
          ref={logsContainerRef}
          className="px-4 py-3 h-[400px] overflow-y-auto bg-muted/30 font-mono text-[12px] leading-relaxed"
        >
          {logs.length > 0 ? (
            logs.map((log, index) => (
              <div
                key={index}
                className={cn(
                  'py-0.5',
                  log.includes('error') || log.includes('Error')
                    ? 'text-destructive'
                    : log.includes('warning') || log.includes('Warning')
                      ? 'text-yellow-600 dark:text-yellow-400'
                      : log.includes('success') || log.includes('Success')
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-muted-foreground',
                )}
              >
                {log}
              </div>
            ))
          ) : (
            <div className="flex items-center gap-2 text-muted-foreground">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              Waiting for build logs...
            </div>
          )}
        </div>
      </div>

      {/* Status-specific messages */}
      {status === 'ready' && (
        <div className="rounded-xl border border-green-500/30 bg-green-500/10 p-4">
          <div className="flex items-center gap-3">
            <CheckCircle2 className="h-5 w-5 text-green-500 shrink-0" />
            <div>
              <p className="text-[13px] font-medium text-green-600 dark:text-green-400">
                Deployment successful!
              </p>
              <p className="text-[12px] text-muted-foreground">
                Redirecting to completion screen...
              </p>
            </div>
          </div>
        </div>
      )}

      {status === 'failed' && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/10 p-4">
          <div className="flex items-center gap-3">
            <XCircle className="h-5 w-5 text-destructive shrink-0" />
            <div>
              <p className="text-[13px] font-medium text-destructive">
                Deployment failed
              </p>
              <p className="text-[12px] text-muted-foreground">
                Check the build logs for more details
              </p>
            </div>
          </div>
        </div>
      )}
    </WizardLayout>
  )
}
