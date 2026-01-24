import { useParams, Link } from '@tanstack/react-router'
import { useMemo } from 'react'
import {
  useProjectSite,
  useSiteDeployments,
  useSiteDeployment,
  useDeploymentProxyRules,
} from '@/lib/react-query/hooks'
import { CheckCircle2, Loader2, Clock, AlertCircle, Globe } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  getDeploymentStatusBadge,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}


export function SiteOverviewView() {
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )

  // Fetch recent deployments (first 4)
  const { data: recentDeploymentsData } = useSiteDeployments(
    projectId,
    siteId,
    0,
    4,
    [
      Query.select([
        'status',
        'type',
        'resourceId',
        'providerRepositoryUrl',
        'providerRepositoryOwner',
        'providerRepositoryName',
        'providerBranchUrl',
        'providerBranch',
        'providerCommitMessage',
        'providerCommitHash',
        'providerCommitUrl',
        '$createdAt',
      ]),
    ],
  )

  // Fetch production-ready deployments
  const { data: productionDeploymentsData } = useSiteDeployments(
    projectId,
    siteId,
    0,
    1,
    [
      Query.equal('status', 'ready'),
      Query.equal('activate', true),
      Query.select([
        'buildDuration',
        'totalSize',
        'sourceSize',
        'buildSize',
        'type',
        'resourceId',
        '$createdAt',
      ]),
    ],
  )

  // Fetch active deployment details
  const { data: activeDeployment } = useSiteDeployment(
    projectId,
    siteId,
    site?.deploymentId || undefined,
  )

  // Fetch proxy rules for active deployment
  const { data: proxyRulesData } = useDeploymentProxyRules(
    projectId,
    siteId,
    site?.deploymentId || undefined,
  )

  const recentDeployments = recentDeploymentsData?.deployments || []
  const productionDeployment = productionDeploymentsData?.deployments?.[0]
  const proxyRules = proxyRulesData?.rules || []

  const isBuilding =
    activeDeployment?.status === 'building' ||
    activeDeployment?.status === 'processing'

  if (siteLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-muted-foreground">Loading site...</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {isBuilding && (
            <Alert variant="default" className="border-blue-500/30">
              <Info className="h-4 w-4 text-blue-500" />
              <AlertDescription className="text-[12px] text-blue-600/80 dark:text-blue-400/80">
                Your site is currently being deployed.
              </AlertDescription>
            </Alert>
          )}

          {/* Active Deployment Card */}
          {activeDeployment && activeDeployment.status === 'ready' && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Active deployment
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                  {/* Deployed */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      Deployed
                    </div>
                    <div className="text-[13px] text-foreground">
                      <DateTooltip date={activeDeployment.$createdAt} />
                    </div>
                  </div>

                  {/* Build duration */}
                  {productionDeployment?.buildDuration &&
                    !isDeploymentTimeout(
                      productionDeployment.status,
                      productionDeployment.$createdAt,
                    ) && (
                      <div>
                        <div className="text-[12px] text-muted-foreground mb-1.5">
                          Build duration
                        </div>
                        <div className="text-[13px] text-foreground">
                          {formatDuration(productionDeployment.buildDuration)}
                        </div>
                      </div>
                    )}

                  {/* Total size */}
                  {productionDeployment?.totalSize && (
                    <div>
                      <div className="text-[12px] text-muted-foreground mb-1.5">
                        Total size
                      </div>
                      <div className="text-[13px] text-foreground">
                        {formatBytes(productionDeployment.totalSize)}
                      </div>
                    </div>
                  )}

                  {/* Domains */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      Domains
                    </div>
                    <div className="text-[13px] text-foreground">
                      {proxyRules.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                          {proxyRules.slice(0, 2).map((rule) => (
                            <a
                              key={rule.$id}
                              href={`https://${rule.domain}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="font-mono text-[11px] text-primary hover:underline"
                            >
                              {rule.domain}
                            </a>
                          ))}
                          {proxyRules.length > 2 && (
                            <span className="text-[11px] text-muted-foreground">
                              +{proxyRules.length - 2} more
                            </span>
                          )}
                        </div>
                      ) : (
                        <span className="text-muted-foreground">No domains</span>
                      )}
                    </div>
                  </div>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Link
                  to="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
                  params={{
                    projectId: projectId!,
                    siteId: siteId!,
                    deploymentId: activeDeployment.$id,
                  }}
                >
                  <Button size="sm" variant="outline" className="h-9 text-[13px]">
                    Build logs
                  </Button>
                </Link>
              </div>
            </div>
          )}

          {/* Recent Deployments */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4 flex items-center justify-between">
              <h3 className="text-[15px] font-semibold text-foreground">
                Recent deployments
              </h3>
              <Link
                to="/projects/$projectId/sites/$siteId/deployments"
                params={{ projectId: projectId!, siteId: siteId! }}
                className="text-[13px] text-primary hover:underline"
              >
                View all
              </Link>
            </div>
            <div className="border-t border-border" />
            {recentDeployments.length > 0 ? (
              <div className="divide-y divide-border">
                {recentDeployments.map((deployment) => {
                  const deploymentData = deployment as Models.Deployment
                  const statusBadge = getDeploymentStatusBadge(
                    deploymentData.status || 'unknown',
                    deploymentData.$createdAt,
                  )
                  const isActive = deploymentData.$id === site?.deploymentId
                  const StatusIcon = statusBadge.icon

                  return (
                    <Link
                      key={deploymentData.$id}
                      to="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
                      params={{
                        projectId: projectId!,
                        siteId: siteId!,
                        deploymentId: deploymentData.$id,
                      }}
                      className="block px-6 py-4 hover:bg-muted/30 transition-colors"
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3 min-w-0 flex-1">
                          <div className="flex items-center gap-2 min-w-0 flex-1">
                            <Badge
                              variant={
                                isActive
                                  ? 'active'
                                  : statusBadge.badgeVariant
                              }
                              className="gap-1.5 text-[11px] font-medium shrink-0"
                            >
                              <StatusIcon className="h-3 w-3" />
                              {isActive ? 'Active' : statusBadge.label}
                            </Badge>
                            <div className="min-w-0 flex-1">
                              <CopyableId
                                id={deploymentData.$id}
                                size="xs"
                                className="font-mono"
                              />
                            </div>
                          </div>
                        </div>
                        <div className="text-right shrink-0 ml-4">
                          <DateTooltip
                            date={deploymentData.$createdAt}
                            className="text-[12px] text-muted-foreground"
                          />
                        </div>
                      </div>
                    </Link>
                  )
                })}
              </div>
            ) : (
              <div className="px-6 py-8 text-center">
                <p className="text-[13px] text-muted-foreground">
                  No deployments yet
                </p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
