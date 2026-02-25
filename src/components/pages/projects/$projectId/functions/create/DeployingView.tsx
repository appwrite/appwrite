/**
 * Function Deploying View
 *
 * Shows real-time build progress with logs after creating a function.
 * Reuses BuildLogsCard from sites. Evolves when status becomes ready or failed.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { BuildLogsCard } from '@/components/global/shared/BuildLogsCard'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { getDeploymentStatusBadge } from '@/lib/utils/deployment-status'
import {
  useProjectFunction,
  useFunctionDeployment,
  useFunctionDomains,
} from '@/lib/react-query/hooks'
import { useFunctionWizard } from './WizardContext'
import { ExternalLink, Play, GitBranch } from 'lucide-react'

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

interface DeployingViewProps {
  functionId?: string
  deploymentId?: string
}

export function DeployingView({ functionId, deploymentId }: DeployingViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { formData, resetFormData } = useFunctionWizard()

  const actualFunctionId = functionId || formData.createdFunctionId
  const actualDeploymentId = deploymentId || formData.createdDeploymentId

  const { data: func } = useProjectFunction(projectId, actualFunctionId)
  const { data: deployment } = useFunctionDeployment(
    projectId,
    actualFunctionId,
    actualDeploymentId,
  )
  const { data: domainsData } = useFunctionDomains(
    projectId,
    actualFunctionId,
    0,
    10,
  )
  const domains = domainsData?.rules ?? []

  const [status, setStatus] = useState<string>('building')
  const buildLogs = deployment?.buildLogs ?? ''

  useEffect(() => {
    if (deployment) {
      setStatus(deployment.status)
    }
  }, [deployment])

  const handleGoToFunction = () => {
    if (status === 'ready' || status === 'failed') {
      resetFormData()
    }
    if (actualFunctionId) {
      navigate({
        to: '/projects/$projectId/functions/$functionId',
        params: { projectId: projectId!, functionId: actualFunctionId },
      })
    } else {
      navigate({
        to: '/projects/$projectId/functions',
        params: { projectId: projectId! },
      })
    }
  }

  const statusBadge = deployment
    ? getDeploymentStatusBadge(deployment.status, deployment.$createdAt)
    : null

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

  const primaryDomain = domains?.[0]?.domain
  const functionUrl = primaryDomain
    ? `https://${primaryDomain}`
    : func?.name
      ? `https://${func.name.toLowerCase().replace(/[^a-z0-9-]/g, '-')}.appwrite.network`
      : null

  const sidebarContent =
    func || deployment ? (
      <div className="space-y-4">
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-5 py-4">
            <div className="flex items-start gap-3">
              {func && (
                <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50 shrink-0">
                  <RuntimeIcon
                    runtime={func.runtime}
                    className="h-5 w-5 text-muted-foreground"
                  />
                </div>
              )}
              <div className="flex-1 min-w-0">
                {func && (
                  <>
                    <h3 className="text-[15px] font-semibold text-foreground truncate">
                      {func.name}
                    </h3>
                    <CopyableId id={func.$id} size="xs" className="mt-0.5" />
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
        </div>
      </div>
    ) : null

  return (
    <WizardLayout
      title="Create function"
      fallbackPath={`/projects/${projectId}/functions`}
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <Button
          variant={status === 'ready' ? 'default' : 'outline'}
          onClick={handleGoToFunction}
        >
          Go to function
        </Button>
      }
    >
      <div className="space-y-4">
        {/* Build logs – show while building; hide when ready (keep for failed to debug) */}
        {status !== 'ready' && (
          <BuildLogsCard
            buildLogs={buildLogs}
            durationDisplay={buildDurationDisplay}
            downloadFilename={`build-logs-${actualDeploymentId || 'deployment'}.txt`}
          />
        )}

        {/* Completion content – appears when ready or failed */}
        {(status === 'ready' || status === 'failed') && func && (
          <div
            className="space-y-4 transition-all duration-300 ease-out animate-in fade-in-0 slide-in-from-bottom-4"
            style={{
              animationDuration: '400ms',
              animationFillMode: 'backwards',
            }}
          >
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="p-6">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex items-start gap-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <RuntimeIcon runtime={func.runtime} className="h-6 w-6" />
                    </div>
                    <div>
                      <h3 className="text-[16px] font-semibold text-foreground">
                        {func.name}
                      </h3>
                      <CopyableId id={func.$id} size="xs" />
                      {functionUrl && (
                        <a
                          href={functionUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="mt-2 flex items-center gap-1 text-[12px] text-primary hover:underline"
                        >
                          <ExternalLink className="h-3.5 w-3.5" />
                          {primaryDomain || 'Function URL'}
                        </a>
                      )}
                    </div>
                  </div>
                  {functionUrl && (
                    <Button asChild>
                      <a
                        href={functionUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <ExternalLink className="mr-1.5 h-4 w-4" />
                        Open URL
                      </a>
                    </Button>
                  )}
                </div>
              </div>
            </div>

            {/* Next steps */}
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Next steps
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  Run your function or connect a repository for deployments
                </p>
              </div>
              <div className="border-t border-border" />
              <div className="grid grid-cols-1 sm:grid-cols-2 divide-x divide-y divide-border">
                <Link
                  to="/projects/$projectId/functions/$functionId/executions"
                  params={{
                    projectId: projectId!,
                    functionId: actualFunctionId!,
                  }}
                  className="flex items-center gap-4 px-6 py-4 hover:bg-muted/20 transition-colors cursor-pointer"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Play className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium text-foreground">
                      Create execution
                    </p>
                    <p className="text-[11px] text-muted-foreground mt-0.5">
                      Run your function manually
                    </p>
                  </div>
                </Link>
                {(!func.installationId || !func.providerRepositoryId) && (
                  <Link
                    to="/projects/$projectId/functions/$functionId/settings"
                    params={{
                      projectId: projectId!,
                      functionId: actualFunctionId!,
                    }}
                    className="flex items-center gap-4 px-6 py-4 hover:bg-muted/20 transition-colors cursor-pointer"
                  >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <GitBranch className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-[13px] font-medium text-foreground">
                        Connect repository
                      </p>
                      <p className="text-[11px] text-muted-foreground mt-0.5">
                        Link Git for automatic deployments
                      </p>
                    </div>
                  </Link>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </WizardLayout>
  )
}
