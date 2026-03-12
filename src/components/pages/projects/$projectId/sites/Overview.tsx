import { useParams, Link } from '@tanstack/react-router'
import { useState, useMemo } from 'react'
import {
  useProjectSite,
  useSiteDeployments,
  useSiteDeployment,
  useSiteDomains,
  Dependencies,
} from '@/lib/react-query/hooks'
import {
  Globe,
  Download,
  RefreshCw,
  FileCode,
  Package,
  ChevronDown,
  ExternalLink,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'

import { useQueryClient, useMutation } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { DeploymentDownloadType } from '@appwrite.io/console'
import { formatBytes } from '@/lib/utils/mock-data'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  getDeploymentStatusBadge,
  isDeploymentCompleted,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import { toast } from 'sonner'

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)
  const [activateDialogOpen, setActivateDialogOpen] = useState(false)

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

  const recentDeployments = recentDeploymentsData?.deployments || []
  const productionDeployment = productionDeploymentsData?.deployments?.[0]

  // Use same site domains as Domains tab, then filter to active deployment
  const { rules: siteDomainsRules } = useSiteDomains(
    projectId,
    siteId,
    0,
    100,
    '',
  )

  // Filter to rules that point to the active deployment (same data source as Domains tab), show up to 3
  const activeDomains = useMemo(() => {
    const filtered =
      siteDomainsRules?.filter(
        (rule) =>
          rule.type === 'deployment' &&
          rule.deploymentId === activeDeployment?.$id,
      ) || []
    return filtered
      .sort((a, b) => a.domain.length - b.domain.length)
      .slice(0, 3)
  }, [siteDomainsRules, activeDeployment?.$id])
  const totalActiveDomains =
    siteDomainsRules?.filter(
      (rule) =>
        rule.type === 'deployment' &&
        rule.deploymentId === activeDeployment?.$id,
    ).length ?? 0
  const hasMoreDomains = totalActiveDomains > activeDomains.length

  const isBuilding =
    activeDeployment?.status === 'building' ||
    activeDeployment?.status === 'processing'

  // Handlers
  const handleDownloadSource = () => {
    if (!projectId || !siteId || !activeDeployment) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId,
        deploymentId: activeDeployment.$id,
        type: DeploymentDownloadType.Source,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch {
      toast.error('Failed to download source code')
    }
  }

  const handleDownloadBuild = () => {
    if (!projectId || !siteId || !activeDeployment) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId,
        deploymentId: activeDeployment.$id,
        type: DeploymentDownloadType.Output,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success('Download started')
    } catch {
      toast.error('Failed to download build output')
    }
  }

  // Redeploy mutation
  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeployment) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.createDuplicateDeployment({
        siteId,
        deploymentId: activeDeployment.$id,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.DEPLOYMENTS],
      })
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.SITE],
      })
      toast.success('Deployment rebuild started')
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to redeploy')
    },
  })

  // Activate mutation (disabled for active deployment, but included for consistency)
  const activateMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeployment) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.updateSiteDeployment({
        siteId,
        deploymentId: activeDeployment.$id,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.DEPLOYMENTS],
      })
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.SITE],
      })
      toast.success('Deployment activated successfully')
      setActivateDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to activate deployment')
    },
  })

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeployment) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      throw new Error(
        'Cannot delete the active deployment. Please activate another deployment first.',
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.DEPLOYMENTS],
      })
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.SITE],
      })
      toast.success('Deployment deleted successfully')
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete deployment')
    },
  })

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
          {/* Active Deployment Card - show for both ready and building; realtime updates when status becomes ready */}
          {activeDeployment && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4 flex items-center gap-2">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Active deployment
                </h3>
                {isBuilding && (
                  <Badge variant="warning" className="text-[10px] shrink-0">
                    Building
                  </Badge>
                )}
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
                      {activeDomains.length > 0 ? (
                        <>
                          <div className="flex flex-col gap-1">
                            {activeDomains.map((rule) => (
                              <a
                                key={rule.$id}
                                href={`https://${rule.domain}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 font-mono text-[11px] text-primary hover:underline"
                              >
                                {rule.domain}
                                <ExternalLink className="h-3 w-3 text-muted-foreground shrink-0" />
                              </a>
                            ))}
                            {hasMoreDomains && (
                              <span className="text-[11px] text-muted-foreground">
                                +{totalActiveDomains - activeDomains.length}{' '}
                                more
                              </span>
                            )}
                          </div>
                          <div className="mt-3 pt-2 border-t border-border/60 flex flex-wrap items-center gap-2">
                            <Button
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-[13px] font-medium text-primary"
                              asChild
                            >
                              <Link
                                to="/projects/$projectId/sites/$siteId/domains"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteId!,
                                }}
                              >
                                View all domains
                                {hasMoreDomains && (
                                  <Badge
                                    variant="secondary"
                                    className="ml-1.5 h-4 min-w-4 px-1 text-[10px] font-semibold tabular-nums"
                                  >
                                    +{totalActiveDomains - activeDomains.length}
                                  </Badge>
                                )}
                              </Link>
                            </Button>
                            <span className="text-muted-foreground/60">·</span>
                            <Button
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-[13px] font-medium text-primary"
                              asChild
                            >
                              <Link
                                to="/projects/$projectId/sites/$siteId/domains"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteId!,
                                }}
                              >
                                Add domain
                              </Link>
                            </Button>
                          </div>
                        </>
                      ) : (
                        <div className="mt-2 pt-2 border-t border-border/60 flex flex-wrap items-center gap-2">
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[13px] font-medium text-primary"
                            asChild
                          >
                            <Link
                              to="/projects/$projectId/sites/$siteId/domains"
                              params={{
                                projectId: projectId!,
                                siteId: siteId!,
                              }}
                            >
                              View all domains
                            </Link>
                          </Button>
                          <span className="text-muted-foreground/60">·</span>
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[13px] font-medium text-primary"
                            asChild
                          >
                            <Link
                              to="/projects/$projectId/sites/$siteId/domains"
                              params={{
                                projectId: projectId!,
                                siteId: siteId!,
                              }}
                            >
                              Add domain
                            </Link>
                          </Button>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 text-[13px]"
                    >
                      <Download className="mr-1.5 h-4 w-4" />
                      Download
                      <ChevronDown className="ml-1.5 h-3.5 w-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="z-[200]">
                    <DropdownMenuItem onClick={handleDownloadSource}>
                      <FileCode className="mr-2 h-4 w-4" />
                      Source code
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={handleDownloadBuild}
                      disabled={
                        !isDeploymentCompleted(activeDeployment?.status)
                      }
                      title={
                        !isDeploymentCompleted(activeDeployment?.status)
                          ? 'Build output is available after the deployment has completed.'
                          : undefined
                      }
                    >
                      <Package className="mr-2 h-4 w-4" />
                      Build output
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setRedeployDialogOpen(true)}
                  disabled={redeployMutation.isPending}
                  className="h-9 text-[13px]"
                >
                  <RefreshCw className="mr-1.5 h-4 w-4" />
                  Redeploy
                </Button>
                <Link
                  to="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
                  params={{
                    projectId: projectId!,
                    siteId: siteId!,
                    deploymentId: activeDeployment.$id,
                  }}
                >
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-9 text-[13px]"
                  >
                    Build logs
                  </Button>
                </Link>
                {activeDomains.length > 0 && (
                  <Popover>
                    <PopoverTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-9 text-[13px]"
                      >
                        <Globe className="mr-1.5 h-4 w-4" />
                        Visit
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="z-[200] w-80">
                      <div className="space-y-3">
                        <div>
                          <h4 className="text-[13px] font-semibold text-foreground mb-2">
                            Domains
                          </h4>
                          <div className="space-y-1.5">
                            {activeDomains.map((rule) => (
                              <a
                                key={rule.$id}
                                href={`https://${rule.domain}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-2 p-2 rounded-md hover:bg-muted/50 transition-colors group"
                              >
                                <Globe className="h-3.5 w-3.5 text-muted-foreground group-hover:text-foreground shrink-0" />
                                <span className="text-[12px] font-mono text-foreground group-hover:text-primary flex-1 truncate">
                                  {rule.domain}
                                </span>
                                <ExternalLink className="h-3 w-3 text-muted-foreground group-hover:text-foreground shrink-0" />
                              </a>
                            ))}
                          </div>
                        </div>
                      </div>
                    </PopoverContent>
                  </Popover>
                )}
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
                                isActive ? 'active' : statusBadge.badgeVariant
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
              <div className="px-6 py-4">
                <EmptyState
                  title="No deployments yet"
                  description="Deployments will appear here when available"
                  isEmpty={true}
                />
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Delete Confirmation Dialog */}
      {activeDeployment && (
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Delete deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                Are you sure you want to delete this deployment? This action
                cannot be undone.
              </DialogDescription>
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                className="h-9 text-[13px]"
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteMutation.mutate()}
                disabled={deleteMutation.isPending}
                className="h-9 text-[13px]"
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      {/* Redeploy Confirmation Dialog */}
      {activeDeployment && (
        <Dialog open={redeployDialogOpen} onOpenChange={setRedeployDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Redeploy deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                This will create a new build for this deployment using the
                current site configuration. The original deployment's code will
                be preserved and used for the new build.
              </DialogDescription>
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setRedeployDialogOpen(false)}
                disabled={redeployMutation.isPending}
                className="h-9 text-[13px]"
              >
                Cancel
              </Button>
              <Button
                variant="default"
                onClick={() => redeployMutation.mutate()}
                disabled={redeployMutation.isPending}
                className="h-9 text-[13px]"
              >
                Redeploy
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}

      {/* Activate Confirmation Dialog */}
      {activeDeployment && (
        <Dialog open={activateDialogOpen} onOpenChange={setActivateDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-left">
              <DialogTitle>Activate deployment</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                This will switch the active deployment to this one. All traffic
                will be routed to this deployment once activated.
              </DialogDescription>
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setActivateDialogOpen(false)}
                disabled={activateMutation.isPending}
                className="h-9 text-[13px]"
              >
                Cancel
              </Button>
              <Button
                variant="default"
                onClick={() => activateMutation.mutate()}
                disabled={activateMutation.isPending}
                className="h-9 text-[13px]"
              >
                Activate
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </div>
  )
}
