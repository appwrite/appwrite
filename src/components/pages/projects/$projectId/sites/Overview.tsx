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
  ScrollText,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { cn } from '@/lib/utils'
import { proxyRuleServesActiveDeployment } from '@/lib/utils/proxy-domains'
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
  isDeploymentInProgress,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import { mergeActiveDeploymentForCard } from '@/lib/sites/deployment-screenshots'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { domainUrl } from '@/lib/domains/url'

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

export function View() {
  const t = useT()
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

  const activeDeploymentResolved = useMemo(() => {
    if (!site?.deploymentId) return activeDeployment ?? undefined
    const list = recentDeploymentsData?.deployments ?? []
    const fromList = list.find((d) => d.$id === site.deploymentId)
    return fromList ?? activeDeployment ?? undefined
  }, [recentDeploymentsData?.deployments, site?.deploymentId, activeDeployment])

  const activeDeploymentForCard = useMemo(
    () => mergeActiveDeploymentForCard(activeDeployment, activeDeploymentResolved),
    [activeDeployment, activeDeploymentResolved],
  )
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
      siteDomainsRules?.filter((rule) =>
        proxyRuleServesActiveDeployment(
          rule,
          activeDeploymentResolved?.$id,
        ),
      ) || []
    return filtered
      .sort((a, b) => a.domain.length - b.domain.length)
      .slice(0, 3)
  }, [siteDomainsRules, activeDeploymentResolved?.$id])
  const totalActiveDomains =
    siteDomainsRules?.filter((rule) =>
      proxyRuleServesActiveDeployment(rule, activeDeploymentResolved?.$id),
    ).length ?? 0
  const hasMoreDomains = totalActiveDomains > activeDomains.length

  const isBuilding =
    activeDeploymentResolved != null &&
    isDeploymentInProgress(activeDeploymentResolved.status)

  // Handlers
  const handleDownloadSource = () => {
    if (!projectId || !siteId || !activeDeploymentResolved) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
        type: DeploymentDownloadType.Source,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download source code'))
    }
  }

  const handleDownloadBuild = () => {
    if (!projectId || !siteId || !activeDeploymentResolved) return
    try {
      const projectSdk = sdk.forProject(projectId)
      const url = projectSdk.sites.getDeploymentDownload({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
        type: DeploymentDownloadType.Output,
      })
      const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
      window.open(urlWithMode, '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download build output'))
    }
  }

  // Redeploy mutation
  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeploymentResolved) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.createDuplicateDeployment({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.DEPLOYMENTS],
      })
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.SITE],
      })
      toast.success(t('Deployment rebuild started'))
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to redeploy'))
    },
  })

  // Activate mutation (disabled for active deployment, but included for consistency)
  const activateMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeploymentResolved) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.updateSiteDeployment({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.DEPLOYMENTS],
      })
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.SITE],
      })
      toast.success(t('Deployment activated successfully'))
      setActivateDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to activate deployment'))
    },
  })

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeploymentResolved) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      throw new Error(
        t(
          'Cannot delete the active deployment. Please activate another deployment first.',
        ),
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.DEPLOYMENTS],
      })
      await queryClient.refetchQueries({
        queryKey: [...Dependencies.SITE],
      })
      toast.success(t('Deployment deleted successfully'))
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete deployment'))
    },
  })

  if (siteLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-muted-foreground">{t('Loading site...')}</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Active Deployment Card - show for both ready and building; realtime updates when status becomes ready */}
          {activeDeploymentResolved && activeDeploymentForCard && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4 flex items-center gap-2">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Active deployment')}
                </h3>
                {isBuilding && (
                  <Badge
                    variant="deploymentBuilding"
                    className="text-[10px] shrink-0"
                  >
                    {t('Building')}
                  </Badge>
                )}
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                  {/* Deployed */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      {t('Deployed')}
                    </div>
                    <div className="text-[13px] text-foreground">
                      <DateTooltip
                        date={activeDeploymentForCard.$createdAt}
                      />
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
                          {t('Build duration')}
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
                        {t('Total size')}
                      </div>
                      <div className="text-[13px] text-foreground">
                        {formatBytes(productionDeployment.totalSize)}
                      </div>
                    </div>
                  )}

                  {/* Domains */}
                  <div>
                    <div className="text-[12px] text-muted-foreground mb-1.5">
                      {t('Domains')}
                    </div>
                    <div className="text-[13px] text-foreground">
                      {activeDomains.length > 0 ? (
                        <>
                          <div className="flex flex-col gap-1">
                            {activeDomains.map((rule) => (
                              <a
                                key={rule.$id}
                                href={domainUrl(rule.domain)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 font-mono text-[11px] link-neutral"
                              >
                                {rule.domain}
                                <ExternalLink className="h-3 w-3 text-muted-foreground shrink-0" />
                              </a>
                            ))}
                            {hasMoreDomains && (
                              <span className="text-[11px] text-muted-foreground">
                                +{totalActiveDomains - activeDomains.length}{' '}
                                {t('more')}
                              </span>
                            )}
                          </div>
                          <div
                            className={cn(
                              RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
                              'flex flex-wrap items-center gap-2',
                            )}
                          >
                            <Button
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-[13px] font-medium"
                              asChild
                            >
                              <Link
                                to="/projects/$projectId/sites/$siteId/domains"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteId!,
                                }}
                              >
                                {t('View all domains')}
                                {hasMoreDomains && (
                                  <Badge
                                    variant="secondary"
                                    className="ms-1.5 h-4 min-w-4 px-1 text-[10px] font-semibold tabular-nums"
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
                              className="h-auto p-0 text-[13px] font-medium"
                              asChild
                            >
                              <Link
                                to="/projects/$projectId/sites/$siteId/domains"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteId!,
                                }}
                              >
                                {t('Add domain')}
                              </Link>
                            </Button>
                          </div>
                        </>
                      ) : (
                        <div
                          className={cn(
                            RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
                            'flex flex-wrap items-center gap-2',
                          )}
                        >
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[13px] font-medium"
                            asChild
                          >
                            <Link
                              to="/projects/$projectId/sites/$siteId/domains"
                              params={{
                                projectId: projectId!,
                                siteId: siteId!,
                              }}
                            >
                              {t('View all domains')}
                            </Link>
                          </Button>
                          <span className="text-muted-foreground/60">·</span>
                          <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-[13px] font-medium"
                            asChild
                          >
                            <Link
                              to="/projects/$projectId/sites/$siteId/domains"
                              params={{
                                projectId: projectId!,
                                siteId: siteId!,
                              }}
                            >
                              {t('Add domain')}
                            </Link>
                          </Button>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end [&>*]:w-full sm:[&>*]:w-auto [&_button]:w-full [&_button]:justify-start sm:[&_button]:w-auto sm:[&_button]:justify-center [&_a]:w-full [&_a]:justify-start sm:[&_a]:w-auto sm:[&_a]:justify-center">
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 text-[13px]"
                    >
                      <Download className="me-1.5 h-4 w-4" />
                      {t('Download')}
                      <ChevronDown className="ms-auto sm:ms-1.5 h-3.5 w-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="z-[200]">
                    <DropdownMenuItem onClick={handleDownloadSource}>
                      <FileCode className="me-2 h-4 w-4" />
                      {t('Source code')}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={handleDownloadBuild}
                      disabled={
                        !isDeploymentCompleted(
                          activeDeploymentForCard?.status,
                        )
                      }
                      title={
                        !isDeploymentCompleted(
                          activeDeploymentForCard?.status,
                        )
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
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setRedeployDialogOpen(true)}
                  disabled={redeployMutation.isPending}
                  className="h-9 text-[13px]"
                >
                  <RefreshCw className="me-1.5 h-4 w-4" />
                  {t('Redeploy')}
                </Button>
                <Link
                  to="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
                  params={{
                    projectId: projectId!,
                    siteId: siteId!,
                    deploymentId: activeDeploymentResolved.$id,
                  }}
                >
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-9 text-[13px]"
                  >
                    <ScrollText className="me-1.5 h-4 w-4" />
                    {t('Build logs')}
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
                        <Globe className="me-1.5 h-4 w-4" />
                        {t('Visit')}
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="z-[200] w-80">
                      <div className="space-y-3">
                        <div>
                          <h4 className="text-[13px] font-semibold text-foreground mb-2">
                            {t('Domains')}
                          </h4>
                          <div className="space-y-1.5">
                            {activeDomains.map((rule) => (
                              <a
                                key={rule.$id}
                                href={domainUrl(rule.domain)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-2 p-2 rounded-md hover:bg-muted/50 transition-colors group"
                              >
                                <Globe className="h-3.5 w-3.5 text-muted-foreground group-hover:text-foreground shrink-0" />
                                <span className="text-[12px] font-mono text-foreground group-hover:text-foreground flex-1 truncate">
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
                {t('Recent deployments')}
              </h3>
              <Link
                to="/projects/$projectId/sites/$siteId/deployments"
                params={{ projectId: projectId!, siteId: siteId! }}
                className="link-neutral text-[13px]"
              >
                {t('View all')}
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
                      className={cn(
                        'block px-6 py-4 transition-colors',
                        isActive
                          ? 'bg-muted/40 dark:bg-muted/35 hover:bg-muted/55 dark:hover:bg-muted/50'
                          : 'hover:bg-muted/30',
                      )}
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
                              {isActive ? t('Active') : t(statusBadge.label)}
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
                        <div className="text-end shrink-0 ms-4">
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
                  title={t('No deployments yet')}
                  description={t('Deployments will appear here when available')}
                  isEmpty={true}
                />
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Delete Confirmation Dialog */}
      {activeDeploymentResolved && activeDeploymentForCard && (
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
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
              <DeploymentInfo
                deployment={activeDeploymentForCard}
                showStatus={true}
              />
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
      )}

      {/* Redeploy Confirmation Dialog */}
      {activeDeploymentResolved && activeDeploymentForCard && (
        <Dialog open={redeployDialogOpen} onOpenChange={setRedeployDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>{t('Redeploy deployment')}</DialogTitle>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-4">
              <DialogDescription className="text-[13px] mb-4">
                {t(
                  "This will create a new build for this deployment using the current site configuration. The original deployment's code will be preserved and used for the new build.",
                )}
              </DialogDescription>
              <DeploymentInfo
                deployment={activeDeploymentForCard}
                showStatus={true}
              />
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
      {activeDeploymentResolved && activeDeploymentForCard && (
        <Dialog open={activateDialogOpen} onOpenChange={setActivateDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
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
              <DeploymentInfo
                deployment={activeDeploymentForCard}
                showStatus={true}
              />
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
    </div>
  )
}
