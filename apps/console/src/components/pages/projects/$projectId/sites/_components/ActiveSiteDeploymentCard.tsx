import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTheme } from 'next-themes'
import { Link } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  CheckCircle2,
  ChevronDown,
  Download,
  ExternalLink,
  FileCode,
  GitBranch,
  Globe,
  HelpCircle,
  Moon,
  Package,
  RefreshCw,
  ScrollText,
  Shield,
  Sun,
} from 'lucide-react'
import { DeploymentDownloadType, ImageFormat } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useCreateDeployment } from '../../shared/CreateDeploymentContext'
import { CreateDeploymentDropdown } from '../../shared/CreateDeploymentDropdown'
import { sdk, getSiteScreenshotFilePreviewUrl } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { useAvifSupport } from '@/lib/avif-support'
import { domainUrl } from '@/lib/domains/url'
import { useT } from '@/lib/i18n/translate'
import {
  cancelSiteDeployment,
  Dependencies,
  siteDeploymentQueryOptions,
  useProjectSite,
  useSiteDeployment,
  useSiteDomains,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { mergeActiveDeploymentForCard } from '@/lib/sites/deployment-screenshots'
import {
  SITE_SCREENSHOTS_BUCKET_ID,
  SITE_SCREENSHOT_CARD_HEIGHT,
  SITE_SCREENSHOT_CARD_WIDTH,
} from '@/lib/sites/screenshot-preview-sizes'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import { getDeploymentRepositoryWebUrl } from '@/lib/utils/deployment-repository-url'
import {
  applySettingsRedeploySuccess,
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

export function ActiveSiteDeploymentCard({
  projectId,
  siteId,
}: {
  projectId: string
  siteId: string
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const createDeployment = useCreateDeployment()
  const avifSupported = useAvifSupport()
  const { theme, resolvedTheme } = useTheme()
  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )
  const { data: activeDeployment } = useSiteDeployment(
    projectId,
    siteId,
    site?.deploymentId || undefined,
  )
  const { rules: siteDomainsRules } = useSiteDomains(
    projectId,
    siteId,
    0,
    DOMAINS_DEFAULT_PAGE_SIZE,
    '',
  )

  const [screenshotLoaded, setScreenshotLoaded] = useState(false)
  const [screenshotThemeOverride, setScreenshotThemeOverride] = useState<
    'dark' | 'light' | null
  >(null)
  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)

  const isDark = useMemo(
    () =>
      resolvedTheme === 'dark' ||
      theme === 'dark' ||
      (typeof window !== 'undefined' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches),
    [theme, resolvedTheme],
  )
  const defaultScreenshotTheme =
    resolvedTheme === 'dark' || resolvedTheme === 'light'
      ? resolvedTheme
      : isDark
        ? 'dark'
        : 'light'
  const screenshotTheme = screenshotThemeOverride ?? defaultScreenshotTheme

  const activeDeploymentResolved = activeDeployment ?? undefined
  const activeDeploymentForCard = useMemo(
    () =>
      mergeActiveDeploymentForCard(activeDeployment, activeDeploymentResolved),
    [activeDeployment, activeDeploymentResolved],
  )
  const isBuilding =
    activeDeploymentResolved != null &&
    isDeploymentInProgress(activeDeploymentResolved.status)

  const [, setTick] = useState(0)
  useEffect(() => {
    if (!isBuilding) return
    const interval = setInterval(() => setTick((tick) => tick + 1), 1000)
    return () => clearInterval(interval)
  }, [isBuilding])

  useEffect(() => {
    setScreenshotLoaded(false)
  }, [activeDeploymentResolved?.$id, screenshotTheme])

  const activeDeploymentIdForDomains =
    activeDeploymentResolved?.$id ?? site?.deploymentId
  const activeDomains = useMemo(() => {
    const filtered =
      siteDomainsRules?.filter((rule) =>
        proxyRuleServesActiveDeployment(rule, activeDeploymentIdForDomains),
      ) || []
    return filtered
      .sort((a, b) => a.domain.length - b.domain.length)
      .slice(0, 3)
  }, [siteDomainsRules, activeDeploymentIdForDomains])
  const totalActiveDomains = useMemo(
    () =>
      siteDomainsRules?.filter((rule) =>
        proxyRuleServesActiveDeployment(rule, activeDeploymentIdForDomains),
      ).length ?? 0,
    [siteDomainsRules, activeDeploymentIdForDomains],
  )
  const hasMoreDomains = totalActiveDomains > activeDomains.length
  const vcsProvider = activeDeploymentResolved
    ? detectVcsProvider(activeDeploymentResolved)
    : null

  const handleDownloadSource = () => {
    if (!activeDeploymentResolved) return
    try {
      const url = sdk.forProject(projectId).sites.getDeploymentDownload({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
        type: DeploymentDownloadType.Source,
      })
      window.open(withAdminMode(url), '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download source code'))
    }
  }

  const handleDownloadBuild = () => {
    if (!activeDeploymentResolved) return
    if (!canDownloadDeploymentBuildOutput(activeDeploymentResolved.status))
      return
    try {
      const url = sdk.forProject(projectId).sites.getDeploymentDownload({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
        type: DeploymentDownloadType.Output,
      })
      window.open(withAdminMode(url), '_blank')
      toast.success(t('Download started'))
    } catch {
      toast.error(t('Failed to download build output'))
    }
  }

  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!activeDeploymentResolved) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      return await sdk.forProject(projectId).sites.createDuplicateDeployment({
        siteId,
        deploymentId: activeDeploymentResolved.$id,
      })
    },
    onSuccess: async (deployment) => {
      await applySettingsRedeploySuccess(queryClient, {
        resourceType: 'site',
        projectId,
        resourceId: siteId,
        resourceQueryKey: ['site', 'project', projectId, siteId],
        deploymentQueryKey: siteDeploymentQueryOptions(
          projectId,
          siteId,
          deployment.$id,
        ).queryKey,
        deploymentsQueryKey: ['deployments', 'site', projectId, siteId],
        deployment,
      })
      toast.success(t('Deployment rebuild started'))
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      clearSettingsRedeployPending(queryClient, 'site', projectId, siteId)
      toast.error(error.message || t('Failed to redeploy'))
    },
  })

  const cancelBuildMutation = useMutation({
    mutationFn: async () => {
      if (!activeDeploymentResolved) {
        throw new Error('Project ID and Site ID are required')
      }
      return await cancelSiteDeployment(
        projectId,
        siteId,
        activeDeploymentResolved.$id,
      )
    },
    onSuccess: async () => {
      setCancelBuildDialogOpen(false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.DEPLOYMENTS,
      })
      await queryClient.refetchQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      toast.success(t('Build cancelled'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to cancel build'))
    },
  })

  if (siteLoading) {
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

  if (!activeDeploymentResolved || !activeDeploymentForCard) {
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
            {t('Create your first deployment to activate this site.')}
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

  const cardDeployment = activeDeploymentForCard as Models.Deployment & {
    screenshotDark?: string
    screenshotLight?: string
  }
  const screenshotId =
    screenshotTheme === 'dark'
      ? cardDeployment.screenshotDark
      : cardDeployment.screenshotLight

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="flex items-center justify-between gap-2 px-6 py-4">
          <div className="flex items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Active deployment')}
            </h3>
            {isBuilding ? (
              <Badge variant="deploymentBuilding" className="shrink-0 text-[10px]">
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
          <div className="flex flex-col gap-6 lg:flex-row">
            <div className="w-full min-w-0 shrink-0 lg:w-1/2">
              <div className="relative aspect-video w-full overflow-hidden rounded-lg border border-border bg-muted">
                {screenshotId ? (
                  <div className="group absolute inset-0">
                    <img
                      key={screenshotId}
                      src={getSiteScreenshotFilePreviewUrl(projectId, {
                        bucketId: SITE_SCREENSHOTS_BUCKET_ID,
                        fileId: screenshotId,
                        width: SITE_SCREENSHOT_CARD_WIDTH,
                        height: SITE_SCREENSHOT_CARD_HEIGHT,
                        output: avifSupported ? ImageFormat.Avif : undefined,
                      })}
                      alt={t('Deployment screenshot')}
                      onLoad={() => setScreenshotLoaded(true)}
                      className={cn(
                        'h-full w-full object-cover transition-opacity duration-500',
                        screenshotLoaded ? 'opacity-100' : 'opacity-0',
                      )}
                    />
                    {site ? (
                      <div className="absolute bottom-2 start-2">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/50 bg-background/95 backdrop-blur-sm">
                          <FrameworkIcon
                            framework={
                              (site as { buildFramework?: string }).buildFramework ||
                              (site as { buildFrameworkId?: string })
                                .buildFrameworkId ||
                              site.framework
                            }
                            size="sm"
                          />
                        </div>
                      </div>
                    ) : null}
                    <div className="absolute top-2 end-2 opacity-0 transition-opacity group-hover:opacity-100">
                      <div className="flex items-center gap-1 rounded-lg border border-border bg-background/95 p-1 backdrop-blur-sm">
                        <button
                          onClick={() => {
                            setScreenshotThemeOverride('light')
                            setScreenshotLoaded(false)
                          }}
                          className={cn(
                            'rounded p-1.5 transition-colors',
                            screenshotTheme === 'light'
                              ? 'bg-primary text-primary-foreground'
                              : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                          )}
                          title={t('Light screenshot')}
                        >
                          <Sun className="h-3.5 w-3.5" />
                        </button>
                        <button
                          onClick={() => {
                            setScreenshotThemeOverride('dark')
                            setScreenshotLoaded(false)
                          }}
                          className={cn(
                            'rounded p-1.5 transition-colors',
                            screenshotTheme === 'dark'
                              ? 'bg-primary text-primary-foreground'
                              : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                          )}
                          title={t('Dark screenshot')}
                        >
                          <Moon className="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20">
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(0,0,0,0.02),transparent_70%)] dark:bg-[radial-gradient(circle_at_50%_50%,rgba(255,255,255,0.02),transparent_70%)]" />
                    <p className="relative text-[12px] font-medium text-muted-foreground/60">
                      {t('Preview not available')}
                    </p>
                    {site ? (
                      <div className="absolute bottom-2 start-2">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/50 bg-background/95 backdrop-blur-sm">
                          <FrameworkIcon
                            framework={
                              (site as { buildFramework?: string }).buildFramework ||
                              (site as { buildFrameworkId?: string })
                                .buildFrameworkId ||
                              site.framework
                            }
                            size="sm"
                          />
                        </div>
                      </div>
                    ) : null}
                  </div>
                )}
              </div>
            </div>

            <div className="flex-1 lg:w-1/2">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <div className="mb-1.5 text-[12px] text-muted-foreground">
                    {t('Deployed')}
                  </div>
                  <div className="text-[13px] text-foreground">
                    <DateTooltip date={cardDeployment.$createdAt} />
                  </div>
                </div>

                {(cardDeployment.buildDuration ||
                  isDeploymentInProgress(cardDeployment.status)) &&
                !isDeploymentTimeout(
                  cardDeployment.status,
                  cardDeployment.$createdAt,
                ) ? (
                  <div>
                    <div className="mb-1.5 text-[12px] text-muted-foreground">
                      {t('Build duration')}
                    </div>
                    <div className="text-[13px] text-foreground">
                      {isDeploymentInProgress(cardDeployment.status)
                        ? formatDuration(
                            Math.max(
                              0,
                              Math.floor(
                                (Date.now() -
                                  new Date(cardDeployment.$createdAt).getTime()) /
                                  1000,
                              ),
                            ),
                          )
                        : formatDuration(cardDeployment.buildDuration)}
                    </div>
                  </div>
                ) : null}

                <div>
                  <div className="mb-1.5 text-[12px] text-muted-foreground">
                    {t('Total size')}
                  </div>
                  <div className="text-[13px] text-foreground">
                    {formatSize(
                      (cardDeployment.buildSize || 0) +
                        (cardDeployment.sourceSize || 0),
                    )}
                  </div>
                </div>

                {vcsProvider &&
                cardDeployment.providerRepositoryOwner &&
                cardDeployment.providerRepositoryName ? (
                  <div>
                    <div className="mb-1.5 text-[12px] text-muted-foreground">
                      {t('Source')}
                    </div>
                    <div className="flex min-w-0 items-center gap-1.5 text-[13px] text-foreground">
                      {vcsProvider.icon}
                      {(() => {
                        const repoUrl =
                          getDeploymentRepositoryWebUrl(cardDeployment)
                        const label = `${cardDeployment.providerRepositoryOwner}/${cardDeployment.providerRepositoryName}`
                        return repoUrl ? (
                          <a
                            href={repoUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="truncate no-underline hover:text-foreground"
                            onClick={(e) => e.stopPropagation()}
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
                  <div className="mb-1.5 flex items-center gap-1.5 text-[12px] text-muted-foreground">
                    <span>{t('Global CDN')}</span>
                    <TooltipProvider delayDuration={0}>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex items-center justify-center"
                            onClick={(e) => e.stopPropagation()}
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
                            onClick={(e) => e.stopPropagation()}
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
                            onClick={(e) => e.stopPropagation()}
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
                              "Appwrite's network includes built-in DDoS mitigation to protect against distributed denial-of-service attacks, ensuring uninterrupted access to your sites and maintaining high availability even during high traffic loads.", // pragma: allowlist secret
                            )}
                          </p>
                          <DocsRouteLink
                            href="/docs/products/network"
                            className="link-neutral mt-1.5 inline-block text-[11px]"
                            onClick={(e) => e.stopPropagation()}
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
                      params={{ projectId, siteId }}
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
                      to="/projects/$projectId/sites/$siteId/domains"
                      params={{ projectId, siteId }}
                    >
                      {t('Add domain')}
                    </Link>
                  </Button>
                </div>
              </div>
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
              to="/projects/$projectId/sites/$siteId/deployments"
              params={{ projectId, siteId }}
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
                  !canDownloadDeploymentBuildOutput(
                    activeDeploymentResolved.status,
                  )
                }
                title={
                  !canDownloadDeploymentBuildOutput(
                    activeDeploymentResolved.status,
                  )
                    ? t(
                        'Build output is only available for ready deployments.',
                      )
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
          <Button asChild size="sm" variant="outline" className="h-9 text-[13px]">
            <Link
              to="/projects/$projectId/sites/$siteId/deployments/$deploymentId"
              params={{
                projectId,
                siteId,
                deploymentId: activeDeploymentResolved.$id,
              }}
            >
              <ScrollText className="me-1.5 h-4 w-4" />
              {t('Build logs')}
            </Link>
          </Button>
          {activeDomains.length > 0 ? (
            <Popover>
              <PopoverTrigger asChild>
                <Button variant="outline" size="sm" className="h-9 text-[13px]">
                  <Globe className="me-1.5 h-4 w-4" />
                  {t('Visit')}
                </Button>
              </PopoverTrigger>
              <PopoverContent align="end" className="z-[200] w-80">
                <div className="space-y-3">
                  <div>
                    <h4 className="mb-2 text-[13px] font-semibold text-foreground">
                      {t('Domains')}
                    </h4>
                    <div className="space-y-1.5">
                      {activeDomains.map((rule) => (
                        <a
                          key={rule.$id}
                          href={domainUrl(rule.domain)}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="group flex items-center gap-2 rounded-md p-2 transition-colors hover:bg-muted/50"
                        >
                          <Globe className="h-3.5 w-3.5 shrink-0 text-muted-foreground group-hover:text-foreground" />
                          <span className="flex-1 truncate font-mono text-[12px] text-foreground">
                            {rule.domain}
                          </span>
                          <ExternalLink className="h-3 w-3 shrink-0 text-muted-foreground group-hover:text-foreground" />
                        </a>
                      ))}
                      {hasMoreDomains ? (
                        <Link
                          to="/projects/$projectId/sites/$siteId/domains"
                          params={{ projectId, siteId }}
                          className="flex items-center gap-2 rounded-md p-2 text-[12px] text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                        >
                          <span>
                            {t('View all')} {totalActiveDomains} {t('domains')}
                          </span>
                        </Link>
                      ) : null}
                    </div>
                  </div>
                </div>
              </PopoverContent>
            </Popover>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              disabled
            >
              <Globe className="me-1.5 h-4 w-4" />
              {t('Visit')}
            </Button>
          )}
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
            <DeploymentInfo
              deployment={activeDeploymentResolved}
              showStatus={true}
            />
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
                "This will create a new build for this deployment using the current site configuration. The original deployment's code will be preserved and used for the new build.",
              )}
            </DialogDescription>
            <DeploymentInfo
              deployment={cardDeployment}
              showStatus={true}
            />
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
    </>
  )
}
