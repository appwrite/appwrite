import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  CheckCircle2,
  ChevronDown,
  Download,
  ExternalLink,
  FileCode,
  GitBranch,
  HelpCircle,
  Lock,
  Package,
  Play,
  RefreshCw,
  ScrollText,
  Shield,
} from 'lucide-react'
import { DeploymentDownloadType, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
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
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useCreateDeployment } from '../../shared/CreateDeploymentContext'
import { CreateDeploymentDropdown } from '../../shared/CreateDeploymentDropdown'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { domainUrl } from '@/lib/domains/url'
import { useT } from '@/lib/i18n/translate'
import {
  buildFunctionUpdateParams,
  cancelFunctionDeployment,
  Dependencies,
  functionDeploymentQueryOptions,
  useFunctionDeployment,
  useFunctionDomains,
  useFunctionSpecifications,
  useProject,
  useProjectFunction,
  useProjectRuntimes,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  getFirstEnabledSpecification,
  hasUnavailableSpecifications,
  isSpecificationAllowedInPlan,
  SpecificationType,
} from '@/lib/specifications'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import { getDeploymentRepositoryWebUrl } from '@/lib/utils/deployment-repository-url'
import {
  applySettingsRedeploySuccess,
  cacheUpdatedFunctionOrSite,
  clearSettingsRedeployPending,
} from '@/lib/utils/settings-redeploy-alert'
import {
  canDownloadDeploymentBuildOutput,
  isDeploymentInProgress,
  isDeploymentTimeout,
} from '@/lib/utils/deployment-status'
import { proxyRuleServesActiveDeployment } from '@/lib/utils/proxy-domains'
import { cn } from '@/lib/utils'
import { getVcsProvider } from '@/lib/vcs/providers'
import { CreateExecutionDrawer } from '../CreateExecutionDrawer'

function formatSize(bytes: number | bigint): string {
  return formatDecimalBytes(bytes)
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${minutes}m ${secs}s`
}

function detectVcsProvider(
  deployment: Models.Deployment,
): { name: string; icon: ReactNode } | null {
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
  if (deployment.type === 'git' || deployment.type === 'vcs') {
    if (deployment.providerRepositoryUrl || deployment.providerRepositoryId) {
      return {
        name: 'Git',
        icon: <GitBranch className="h-4 w-4" />,
      }
    }
  }
  return null
}

export function ActiveFunctionDeploymentCard({
  projectId,
  functionId,
}: {
  projectId: string
  functionId: string
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const createDeployment = useCreateDeployment()
  const { project } = useProject(projectId)
  const { data: func, isLoading: functionLoading } = useProjectFunction(
    projectId,
    functionId,
  )
  const { data: activeDeployment } = useFunctionDeployment(
    projectId,
    functionId,
    func?.deploymentId || undefined,
  )
  const { data: domainsData } = useFunctionDomains(
    projectId,
    functionId,
    0,
    DOMAINS_DEFAULT_PAGE_SIZE,
    '',
  )
  const { data: runtimesData } = useProjectRuntimes(projectId)
  const { data: specificationsData } = useFunctionSpecifications(
    projectId,
    SpecificationType.Runtimes,
  )

  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)
  const [executeDrawerOpen, setExecuteDrawerOpen] = useState(false)
  const [runtimeLimitsDialogOpen, setRuntimeLimitsDialogOpen] = useState(false)
  const [selectedSpecification, setSelectedSpecification] = useState('')

  const isBuilding =
    activeDeployment != null && isDeploymentInProgress(activeDeployment.status)

  const [, setTick] = useState(0)
  useEffect(() => {
    if (!isBuilding) return
    const interval = setInterval(() => setTick((tick) => tick + 1), 1000)
    return () => clearInterval(interval)
  }, [isBuilding])

  const activeDomains = useMemo(() => {
    const filtered =
      domainsData?.rules?.filter((rule) =>
        proxyRuleServesActiveDeployment(rule, activeDeployment?.$id),
      ) || []
    return filtered
      .sort((a, b) => a.domain.length - b.domain.length)
      .slice(0, 3)
  }, [domainsData?.rules, activeDeployment?.$id])
  const totalActiveDomains =
    domainsData?.rules?.filter((rule) =>
      proxyRuleServesActiveDeployment(rule, activeDeployment?.$id),
    ).length ?? 0
  const hasMoreDomains = totalActiveDomains > activeDomains.length
  const vcsProvider = activeDeployment
    ? detectVcsProvider(activeDeployment)
    : null
  const runtimeName =
    runtimesData?.runtimes?.find((runtime) => runtime.$id === func?.runtime)
      ?.name ||
    func?.runtime ||
    'N/A'
  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData?.specifications],
  )
  const specification = specifications.find(
    (spec) => spec.slug === func?.runtimeSpecification,
  )
  const specificationText = specification
    ? `${specification.cpus} CPU, ${specification.memory}MB RAM`
    : t('Not set')

  useEffect(() => {
    if (!runtimeLimitsDialogOpen || specifications.length === 0) return
    const currentSpec = func?.runtimeSpecification
    if (currentSpec) {
      const spec = specifications.find((item) => item.slug === currentSpec)
      if (spec && isSpecificationAllowedInPlan(spec)) {
        setSelectedSpecification(currentSpec)
        return
      }
    }
    setSelectedSpecification(
      getFirstEnabledSpecification(specifications)?.slug || '',
    )
  }, [runtimeLimitsDialogOpen, func?.runtimeSpecification, specifications])

  const handleDownloadSource = () => {
    if (!activeDeployment) return
    try {
      const url = sdk.forProject(projectId).functions.getDeploymentDownload({
        functionId,
        deploymentId: activeDeployment.$id,
        type: DeploymentDownloadType.Source,
      })
      window.open(withAdminMode(url), '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download source code'))
    }
  }

  const handleDownloadBuild = () => {
    if (!activeDeployment) return
    if (!canDownloadDeploymentBuildOutput(activeDeployment.status)) return
    try {
      const url = sdk.forProject(projectId).functions.getDeploymentDownload({
        functionId,
        deploymentId: activeDeployment.$id,
        type: DeploymentDownloadType.Output,
      })
      window.open(withAdminMode(url), '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download build output'))
    }
  }

  const updateSpecificationMutation = useMutation({
    mutationFn: async (specificationSlug: string) => {
      if (!func) throw new Error('Function is required')
      if (!specificationSlug)
        throw new Error('A specification must be selected')
      return await sdk.forProject(projectId).functions.update(
        buildFunctionUpdateParams(func, {
          runtimeSpecification: specificationSlug,
        }),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Runtime limits updated successfully'))
      cacheUpdatedFunctionOrSite(
        queryClient,
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
      setRuntimeLimitsDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update runtime limits'))
    },
  })

  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!activeDeployment) {
        throw new Error(
          'Project ID, Function ID, and Deployment ID are required',
        )
      }
      return await sdk
        .forProject(projectId)
        .functions.createDuplicateDeployment({
          functionId,
          deploymentId: activeDeployment.$id,
        })
    },
    onSuccess: async (deployment) => {
      await applySettingsRedeploySuccess(queryClient, {
        resourceType: 'function',
        projectId,
        resourceId: functionId,
        resourceQueryKey: ['function', 'project', projectId, functionId],
        deploymentQueryKey: functionDeploymentQueryOptions(
          projectId,
          functionId,
          deployment.$id,
        ).queryKey,
        deploymentsQueryKey: ['deployments', 'function', projectId, functionId],
        deployment,
      })
      toast.success(t('Deployment rebuild started'))
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      clearSettingsRedeployPending(
        queryClient,
        'function',
        projectId,
        functionId,
      )
      toast.error(error.message || t('Failed to redeploy'))
    },
  })

  const cancelBuildMutation = useMutation({
    mutationFn: async () => {
      if (!activeDeployment) {
        throw new Error('Project ID and Function ID are required')
      }
      return await cancelFunctionDeployment(
        projectId,
        functionId,
        activeDeployment.$id,
      )
    },
    onSuccess: async () => {
      setCancelBuildDialogOpen(false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.DEPLOYMENTS,
      })
      await queryClient.refetchQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success(t('Build cancelled'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to cancel build'))
    },
  })

  if (functionLoading) {
    return (
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <div className="h-5 w-40 animate-pulse rounded bg-muted" />
        </div>
        <div className="border-t border-border" />
        <div className="h-48 animate-pulse bg-muted/30" />
      </div>
    )
  }

  if (!activeDeployment) {
    return (
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Active deployment')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-8 text-center">
          <p className="mb-1 text-[14px] font-medium text-foreground">
            {t('There is no active deployment')}
          </p>
          <p className="mb-4 text-[13px] text-muted-foreground">
            {t('Create your first deployment to activate this function.')}
          </p>
          {createDeployment ? (
            <CreateDeploymentDropdown
              onSelectGit={createDeployment.openGitModal}
              onSelectCli={createDeployment.openCliModal}
              onSelectManual={createDeployment.openManualModal}
            />
          ) : null}
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="flex items-center justify-between gap-2 px-6 py-4">
          <div className="flex items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Active deployment')}
            </h3>
            {isBuilding ? (
              <Badge
                variant="deploymentBuilding"
                className="shrink-0 text-[10px]"
              >
                {t('Building')}
              </Badge>
            ) : null}
          </div>
          {isBuilding ? (
            <Button
              variant="outline"
              size="sm"
              className="h-8 shrink-0 text-[12px]"
              onClick={() => setCancelBuildDialogOpen(true)}
              disabled={cancelBuildMutation.isPending}
            >
              {t('Cancel build')}
            </Button>
          ) : null}
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            <div>
              <div className="mb-1.5 text-[12px] text-muted-foreground">
                {t('Deployed')}
              </div>
              <div className="text-[13px] text-foreground">
                <DateTooltip date={activeDeployment.$createdAt} />
              </div>
            </div>

            {(activeDeployment.buildDuration ||
              isDeploymentInProgress(activeDeployment.status)) &&
            !isDeploymentTimeout(
              activeDeployment.status,
              activeDeployment.$createdAt,
            ) ? (
              <div>
                <div className="mb-1.5 text-[12px] text-muted-foreground">
                  {t('Build duration')}
                </div>
                <div className="text-[13px] text-foreground">
                  {isDeploymentInProgress(activeDeployment.status)
                    ? formatDuration(
                        Math.max(
                          0,
                          Math.floor(
                            (Date.now() -
                              new Date(activeDeployment.$createdAt).getTime()) /
                              1000,
                          ),
                        ),
                      )
                    : formatDuration(activeDeployment.buildDuration)}
                </div>
              </div>
            ) : null}

            <div>
              <div className="mb-1.5 text-[12px] text-muted-foreground">
                {t('Total size')}
              </div>
              <div className="text-[13px] text-foreground">
                {formatSize(
                  (activeDeployment.buildSize || 0) +
                    (activeDeployment.sourceSize || 0),
                )}
              </div>
            </div>

            {vcsProvider &&
            activeDeployment.providerRepositoryOwner &&
            activeDeployment.providerRepositoryName ? (
              <div>
                <div className="mb-1.5 text-[12px] text-muted-foreground">
                  {t('Source')}
                </div>
                <div className="flex min-w-0 items-center gap-1.5 text-[13px] text-foreground">
                  {vcsProvider.icon}
                  {(() => {
                    const repoUrl =
                      getDeploymentRepositoryWebUrl(activeDeployment)
                    const label = `${activeDeployment.providerRepositoryOwner}/${activeDeployment.providerRepositoryName}`
                    return repoUrl ? (
                      <a
                        href={repoUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="truncate no-underline hover:text-foreground"
                        onClick={(event) => event.stopPropagation()}
                      >
                        {label}
                      </a>
                    ) : (
                      <span className="truncate">{label}</span>
                    )
                  })()}
                </div>
              </div>
            ) : null}

            <div>
              <div className="mb-1.5 text-[12px] text-muted-foreground">
                {t('Runtime')}
              </div>
              <div className="flex items-center gap-1.5 text-[13px] text-foreground">
                <RuntimeIcon runtime={func?.runtime || ''} size="sm" />
                <span className="font-mono">{runtimeName}</span>
              </div>
            </div>

            <div>
              <div className="mb-1.5 text-[12px] text-muted-foreground">
                {t('Runtime limits')}
              </div>
              <div className="flex items-center gap-2">
                <span className="font-mono text-[13px] text-foreground">
                  {specificationText}
                </span>
                <button
                  type="button"
                  onClick={() => setRuntimeLimitsDialogOpen(true)}
                  className="cursor-pointer text-[11px] text-muted-foreground underline hover:text-foreground"
                >
                  {t('Update')}
                </button>
              </div>
            </div>

            <div>
              <div className="mb-1.5 flex items-center gap-1.5 text-[12px] text-muted-foreground">
                <span>{t('Global CDN')}</span>
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        type="button"
                        className="inline-flex items-center justify-center"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="right" className="max-w-xs">
                      <p className="mb-1.5 text-[12px] font-medium text-background">
                        {t('Content Delivery Network')}
                      </p>
                      <p className="text-[11px] text-background/90">
                        {t(
                          "Appwrite's CDN provides global coverage with 120+ points of presence worldwide, reducing latency through edge caching and content optimization. All content is delivered over TLS for secure, encrypted connections.", // pragma: allowlist secret
                        )}
                      </p>
                      <DocsRouteLink
                        href="/docs/products/network/cdn"
                        className="link-neutral mt-1.5 inline-block text-[11px]"
                        onClick={(event) => event.stopPropagation()}
                      >
                        {t('Learn more →')}
                      </DocsRouteLink>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
              <div className="flex items-center gap-1.5">
                <CheckCircle2 className="h-4 w-4 text-green-500" />
                <span className="text-[13px] font-medium text-foreground">
                  {t('Connected')}
                </span>
              </div>
            </div>

            <div>
              <div className="mb-1.5 flex items-center gap-1.5 text-[12px] text-muted-foreground">
                <span>{t('DDoS protection')}</span>
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        type="button"
                        className="inline-flex items-center justify-center"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="right" className="max-w-xs">
                      <p className="mb-1.5 text-[12px] font-medium text-background">
                        {t('DDoS Mitigation')}
                      </p>
                      <p className="text-[11px] text-background/90">
                        {t(
                          "Appwrite's network includes built-in DDoS mitigation to protect against distributed denial-of-service attacks, ensuring uninterrupted access to your functions and maintaining high availability even during high traffic loads.", // pragma: allowlist secret
                        )}
                      </p>
                      <DocsRouteLink
                        href="/docs/products/network"
                        className="link-neutral mt-1.5 inline-block text-[11px]"
                        onClick={(event) => event.stopPropagation()}
                      >
                        {t('Learn more →')}
                      </DocsRouteLink>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
              <div className="flex items-center gap-1.5">
                <Shield className="h-4 w-4 text-green-500" />
                <span className="text-[13px] font-medium text-foreground">
                  {t('Active')}
                </span>
              </div>
            </div>
          </div>

          <div className="mt-4 border-t border-border pt-4">
            <div className="mb-1.5 text-[12px] text-muted-foreground">
              {t('Domains')}
            </div>
            {activeDomains.length > 0 ? (
              <>
                <div className="flex flex-col gap-1">
                  {activeDomains.map((rule) => (
                    <a
                      key={rule.$id}
                      href={domainUrl(rule.domain)}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex min-w-0 max-w-full items-center gap-1.5 font-mono text-[13px] link-neutral"
                    >
                      <span className="truncate">{rule.domain}</span>
                      <ExternalLink className="h-3 w-3 shrink-0 text-muted-foreground" />
                    </a>
                  ))}
                </div>
                {hasMoreDomains ? (
                  <p className="mt-1.5 text-[11px] text-muted-foreground">
                    +{totalActiveDomains - activeDomains.length} {t('more')}
                  </p>
                ) : null}
              </>
            ) : null}
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
                  to="/projects/$projectId/functions/$functionId/domains"
                  params={{ projectId, functionId }}
                >
                  {t('View all domains')}
                  {hasMoreDomains ? (
                    <Badge
                      variant="secondary"
                      className="ms-1.5 h-4 min-w-4 px-1 text-[10px] font-semibold tabular-nums"
                    >
                      +{totalActiveDomains - activeDomains.length}
                    </Badge>
                  ) : null}
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
                  to="/projects/$projectId/functions/$functionId/domains"
                  params={{ projectId, functionId }}
                >
                  {t('Add domain')}
                </Link>
              </Button>
            </div>
          </div>
        </div>

        <div className="flex flex-col gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <Button
            asChild
            size="sm"
            variant="outline"
            className="h-9 w-full justify-start text-[13px] sm:w-auto sm:justify-center"
          >
            <Link
              to="/projects/$projectId/functions/$functionId/deployments"
              params={{ projectId, functionId }}
            >
              {t('View all')}
            </Link>
          </Button>
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end [&>*]:w-full sm:[&>*]:w-auto [&_a]:w-full [&_a]:justify-start sm:[&_a]:w-auto sm:[&_a]:justify-center [&_button]:w-full [&_button]:justify-start sm:[&_button]:w-auto sm:[&_button]:justify-center">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="h-9 text-[13px]">
                <Download className="me-1.5 h-4 w-4" />
                {t('Download')}
                <ChevronDown className="ms-auto h-3.5 w-3.5 sm:ms-1.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="z-[200]">
              <DropdownMenuItem onClick={handleDownloadSource}>
                <MenuItemContent icon={FileCode}>
                  {t('Source code')}
                </MenuItemContent>
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={handleDownloadBuild}
                disabled={
                  !canDownloadDeploymentBuildOutput(activeDeployment.status)
                }
                title={
                  !canDownloadDeploymentBuildOutput(activeDeployment.status)
                    ? t('Build output is only available for ready deployments.')
                    : undefined
                }
              >
                <MenuItemContent icon={Package}>
                  {t('Build output')}
                </MenuItemContent>
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
          <Button
            asChild
            size="sm"
            variant="outline"
            className="h-9 text-[13px]"
          >
            <Link
              to="/projects/$projectId/functions/$functionId/deployments/$deploymentId"
              params={{
                projectId,
                functionId,
                deploymentId: activeDeployment.$id,
              }}
            >
              <ScrollText className="me-1.5 h-4 w-4" />
              {t('Build logs')}
            </Link>
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => setExecuteDrawerOpen(true)}
            className="h-9 text-[13px]"
          >
            <Play className="me-1.5 h-4 w-4" />
            {t('Execute')}
          </Button>
          </div>
        </div>
      </div>

      <Dialog
        open={cancelBuildDialogOpen}
        onOpenChange={setCancelBuildDialogOpen}
      >
        <DialogContent className="p-0 sm:max-w-md">
          <DialogHeader className="px-6 pb-4 pt-6 text-start">
            <DialogTitle>{t('Cancel build')}</DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              {t('Stop the current deployment? You can deploy again later.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <DeploymentInfo deployment={activeDeployment} showStatus={true} />
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
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
              {t('Cancel build')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={redeployDialogOpen} onOpenChange={setRedeployDialogOpen}>
        <DialogContent className="p-0 sm:max-w-md">
          <DialogHeader className="px-6 pb-4 pt-6 text-start">
            <DialogTitle>{t('Redeploy deployment')}</DialogTitle>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <DialogDescription className="mb-4 text-[13px]">
              {t(
                "This will create a new build for this deployment using the current function configuration. The original deployment's code will be preserved and used for the new build.",
              )}
            </DialogDescription>
            <DeploymentInfo deployment={activeDeployment} showStatus={true} />
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
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

      {specifications.length > 0 ? (
        <Dialog
          open={runtimeLimitsDialogOpen}
          onOpenChange={setRuntimeLimitsDialogOpen}
        >
          <DialogContent className="p-0 sm:max-w-md">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Update Runtime Limits')}</DialogTitle>
              <DialogDescription className="mt-2 text-[13px]">
                {t('Select the runtime specification for your function')}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-0">
              <RadioGroup
                value={selectedSpecification}
                onValueChange={(value) => {
                  const spec = specifications.find(
                    (item) => item.slug === value,
                  )
                  if (spec && !isSpecificationAllowedInPlan(spec)) return
                  setSelectedSpecification(value)
                }}
                className="space-y-2"
              >
                <div className="max-h-[320px] divide-y divide-border overflow-hidden overflow-y-auto rounded-lg border border-border bg-card/50">
                  {specifications.map((spec) => {
                    const isSelected = selectedSpecification === spec.slug
                    const isEnabled = isSpecificationAllowedInPlan(spec)
                    return (
                      <div
                        key={spec.slug}
                        className="first:rounded-t-lg last:rounded-b-lg [&:not(:first-child)]:border-t-0"
                      >
                        <RadioGroupItem
                          value={spec.slug}
                          id={`overview-spec-${spec.slug}`}
                          className="peer sr-only"
                          disabled={!isEnabled}
                        />
                        <Label
                          htmlFor={`overview-spec-${spec.slug}`}
                          className={cn(
                            'flex items-center gap-3 px-3 py-2.5 transition-colors',
                            isEnabled && 'cursor-pointer hover:bg-accent',
                            isSelected && isEnabled && 'bg-accent',
                            !isEnabled && 'cursor-not-allowed opacity-60',
                          )}
                          onClick={(event) => {
                            if (!isEnabled) {
                              event.preventDefault()
                              event.stopPropagation()
                            }
                          }}
                        >
                          <div className="shrink-0">
                            <div
                              className={cn(
                                'flex h-3.5 w-3.5 items-center justify-center rounded-full border-2 transition-colors',
                                isSelected && isEnabled
                                  ? 'border-foreground'
                                  : 'border-muted-foreground',
                                !isEnabled && 'border-muted-foreground/50',
                              )}
                            >
                              {isSelected && isEnabled ? (
                                <div className="h-1.5 w-1.5 rounded-full bg-foreground" />
                              ) : null}
                            </div>
                          </div>
                          <div className="flex min-w-0 flex-1 flex-col">
                            <div className="flex items-center gap-2">
                              <span className="text-[13px] font-medium text-foreground">
                                {spec.cpus} CPU, {spec.memory}MB RAM
                              </span>
                              {!isEnabled ? (
                                <Lock className="h-3 w-3 shrink-0 text-muted-foreground" />
                              ) : null}
                            </div>
                            <span className="mt-0.5 text-[11px] leading-tight text-muted-foreground">
                              {!isEnabled
                                ? t('Upgrade to unlock this specification')
                                : spec.slug}
                            </span>
                          </div>
                        </Label>
                      </div>
                    )
                  })}
                </div>
              </RadioGroup>
              {hasUnavailableSpecifications(specifications) ? (
                <div className="mt-3">
                  <SpecificationsUpgradeNote
                    orgId={project?.teamId}
                    showContactSales
                  />
                </div>
              ) : null}
            </div>
            <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setRuntimeLimitsDialogOpen(false)}
                disabled={updateSpecificationMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                onClick={() =>
                  updateSpecificationMutation.mutate(selectedSpecification)
                }
                disabled={
                  !selectedSpecification ||
                  selectedSpecification === func?.runtimeSpecification ||
                  updateSpecificationMutation.isPending
                }
              >
                {t('Update')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      ) : null}

      <CreateExecutionDrawer
        open={executeDrawerOpen}
        onOpenChange={setExecuteDrawerOpen}
        functionId={functionId}
        func={func ?? null}
      />
    </>
  )
}
