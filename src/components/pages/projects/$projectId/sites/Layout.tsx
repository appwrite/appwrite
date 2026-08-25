import React, { useState, useMemo, useEffect } from 'react'
import {
  Outlet,
  useParams,
  useNavigate,
  useLocation,
} from '@tanstack/react-router'
import {
  useQueryClient,
  useMutation,
  useIsFetching,
} from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import type { Models } from '@appwrite.io/console'
import {
  useProjectSite,
  useSiteDeployment,
  siteDeploymentQueryOptions,
  useProject,
  useOrganizationScopes,
  cancelSiteDeployment,
} from '@/lib/react-query/hooks'
import { canShowSiteSettingsTab } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle, Info } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import {
  getQueryParam,
  queryParamToMap,
  mapToQueryParam,
  deploymentsFilterColumns,
  executionsFilterColumns,
  proxyRulesFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { CreateDeploymentDropdown } from '../shared/CreateDeploymentDropdown'
import { CreateGitDeploymentModal } from '../shared/CreateGitDeploymentModal'
import { CreateCliDeploymentModal } from '../shared/CreateCliDeploymentModal'
import { CreateManualDeploymentModal } from '../shared/CreateManualDeploymentModal'
import { CreateDeploymentProvider } from '../shared/CreateDeploymentContext'
import { useT } from '@/lib/i18n/translate'

/** When provided, Deployments view renders this below the active deployment card (filter + create). */
export const DeploymentsToolbarContext =
  React.createContext<React.ReactNode>(null)

export function Layout() {
  return (
    <RefreshProvider>
      <SiteLayoutContent />
    </RefreshProvider>
  )
}

function SiteLayoutContent() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { triggerRefresh, hasRefreshHandler } = useRefresh()
  const siteLogsListRefreshing =
    useIsFetching({
      queryKey: ['logs', 'site', projectId, siteId],
    }) > 0
  const siteUsageRefreshing =
    useIsFetching({
      queryKey: ['usage-events'],
      predicate: (query) => query.queryKey.includes(siteId),
    }) > 0
  const { data: site } = useProjectSite(projectId, siteId)

  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const sitesIndex = pathParts.findIndex((part) => part === 'sites')

    if (sitesIndex >= 0 && pathParts[sitesIndex + 2]) {
      const tab = pathParts[sitesIndex + 2]
      if (
        [
          'deployments',
          'logs',
          'domains',
          'usage',
          'variables',
          'settings',
        ].includes(tab)
      ) {
        return tab
      }
    }

    return 'deployments'
  }, [location.pathname])

  const activeDeploymentId = site?.deploymentId
  const isLogsTab = activeTab === 'logs'
  const cachedActiveDeployment =
    projectId && siteId && activeDeploymentId
      ? queryClient.getQueryData<Models.Deployment>(
          siteDeploymentQueryOptions(projectId, siteId, activeDeploymentId)
            .queryKey,
        )
      : undefined
  const { data: activeDeploymentFromQuery } = useSiteDeployment(
    projectId,
    siteId,
    isLogsTab ? undefined : activeDeploymentId,
  )
  const activeDeployment = activeDeploymentFromQuery ?? cachedActiveDeployment

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowSiteSettingsTab(access, features)

  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)

  const cancelBuildMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !activeDeployment?.$id) {
        throw new Error('Project ID, Site ID, and Deployment ID are required')
      }
      return await cancelSiteDeployment(projectId, siteId, activeDeployment.$id)
    },
    onSuccess: async () => {
      setCancelBuildDialogOpen(false)
      await queryClient.refetchQueries({
        queryKey: ['deployments', 'site', projectId, siteId],
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
        queryKey: ['deployments', 'site', projectId, siteId],
      })
      await queryClient.refetchQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      toast.success(t('Deployment rebuild started'))
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to redeploy'))
    },
  })

  const handleCancelBuild = () => setCancelBuildDialogOpen(true)

  const isBuilding = useMemo(
    () =>
      activeDeployment?.status === 'building' ||
      activeDeployment?.status === 'processing',
    [activeDeployment?.status],
  )

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

  const [gitDeployOpen, setGitDeployOpen] = useState(false)
  const [cliDeployOpen, setCliDeployOpen] = useState(false)
  const [manualDeployOpen, setManualDeployOpen] = useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)

  const siteFilterMap = useMemo(() => {
    const search = location.search
    // TanStack Router may expose search as a parsed object; use it so filter count/active filters show
    const queryParam =
      typeof search === 'object' && search !== null && 'query' in search
        ? ((search as { query?: string }).query ?? null)
        : getQueryParam(
            new URL(
              location.pathname +
                (typeof search === 'string' ? search || '' : ''),
              typeof window !== 'undefined'
                ? window.location.origin
                : 'http://dummy',
            ),
          )
    return queryParamToMap(queryParam)
  }, [location.pathname, location.search])

  const siteFilterColumns = useMemo(() => {
    if (activeTab === 'deployments') return deploymentsFilterColumns
    if (activeTab === 'logs') return executionsFilterColumns
    if (activeTab === 'domains') return proxyRulesFilterColumns
    return deploymentsFilterColumns
  }, [activeTab])

  const applySiteFilter = (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(siteFilterMap)
    if (replaceKey) newMap.delete(replaceKey)
    newMap.set(key, queryStr)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: mapToQueryParam(newMap),
        page: 1,
      }),
      replace: true,
    })
  }
  const removeSiteFilter = (key: CompactFilterKey) => {
    const newMap = new Map(siteFilterMap)
    newMap.delete(key)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
        page: newMap.size > 0 ? 1 : undefined,
      }),
      replace: true,
    })
  }
  const clearAllSiteFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: undefined,
      }),
      replace: true,
    })
  }

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'deployments',
        label: t('Deployments'),
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'domains',
        label: t('Domains'),
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'logs',
        label: t('Logs'),
        to: '/projects/$projectId/sites/$siteId/logs',
        params: { projectId: projectId!, siteId: siteId! },
      },
      ...(features.usageStats
        ? [
            {
              id: 'usage' as const,
              label: t('Usage'),
              to: '/projects/$projectId/sites/$siteId/usage',
              params: { projectId: projectId!, siteId: siteId! },
            },
          ]
        : []),
      ...(showSettingsTab
        ? [
            {
              id: 'variables' as const,
              label: t('Variables'),
              to: '/projects/$projectId/sites/$siteId/variables',
              params: { projectId: projectId!, siteId: siteId! },
            },
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/sites/$siteId/settings',
              params: { projectId: projectId!, siteId: siteId! },
            },
          ]
        : []),
    ],
    [features.usageStats, projectId, siteId, showSettingsTab, t],
  )

  // Redirect from settings or variables when user lacks permission
  useEffect(() => {
    if (showSettingsTab || !projectId || !siteId) return
    if (activeTab === 'settings' || activeTab === 'variables') {
      navigate({
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId, siteId },
        replace: true,
      })
    }
  }, [showSettingsTab, activeTab, projectId, siteId, navigate])

  const isLogsTabLayout = activeTab === 'logs'

  const buildingAlert = isBuilding ? (
    <div className="border-b border-border bg-blue-500/5">
      <div
        className={cn(
          'w-full px-4 py-3 sm:px-6',
          activeTab !== 'logs' && 'mx-auto max-w-7xl',
        )}
      >
        <Alert variant="default" className="border-blue-500/30 bg-transparent">
          <Info className="h-4 w-4 text-blue-500 shrink-0" />
          <AlertDescription className="flex flex-1 items-center justify-between gap-3 text-[12px] text-blue-600/80 dark:text-blue-400/80">
            <span>{t('Your site is currently being deployed.')}</span>
            <Button
              variant="outline"
              size="sm"
              className="shrink-0 border-blue-500/40 text-blue-600 hover:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/10"
              onClick={handleCancelBuild}
              disabled={cancelBuildMutation.isPending}
            >
              {t('Cancel build')}
            </Button>
          </AlertDescription>
        </Alert>
      </div>
    </div>
  ) : undefined

  // Settings changes that need a redeploy (API sets live=false)
  const configAlert =
    !isBuilding && site && !site.live ? (
      <div className="border-b border-border bg-amber-500/5">
        <div
          className={cn(
            'w-full px-4 py-3 sm:px-6',
            activeTab !== 'logs' && 'mx-auto max-w-7xl',
          )}
        >
          <Alert
            variant="default"
            className="border-amber-500/30 bg-transparent"
          >
            <AlertCircle className="h-4 w-4 text-amber-500" />
            <div className="flex flex-1 items-start justify-between gap-4">
              <div className="flex-1 min-w-0">
                <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                  {t('Settings changes are not live yet')}
                </AlertTitle>
                <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                  <span className="inline">
                    {t(
                      "You've updated site settings, but they won't take effect until you redeploy. The current deployment is still running with the previous settings.",
                    )}
                  </span>
                </AlertDescription>
              </div>
              <Button
                size="sm"
                className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                onClick={() => setRedeployDialogOpen(true)}
                disabled={
                  !site.deploymentId ||
                  !activeDeployment ||
                  redeployMutation.isPending
                }
              >
                {t('Redeploy')}
              </Button>
            </div>
          </Alert>
        </div>
      </div>
    ) : undefined

  return (
    <CreateDeploymentProvider
      onOpenGit={() => setGitDeployOpen(true)}
      onOpenCli={() => setCliDeployOpen(true)}
      onOpenManual={() => setManualDeployOpen(true)}
    >
      <div
        className={cn(
          'flex min-h-0 flex-1 flex-col',
          isLogsTabLayout && 'h-full',
        )}
      >
        <div
          className={cn(
            isLogsTabLayout && 'sticky top-0 z-20 shrink-0 bg-background',
          )}
        >
          <ServiceHeader
            title={
              <DetailResourceHeaderTitle
                kind="site"
                label={site?.name || t('Site')}
                resourceId={site?.$id ?? ''}
                projectId={projectId}
                back={{
                  onClick: handleBack,
                  'aria-label': t('Back to sites'),
                }}
              />
            }
            tabs={tabs}
            activeTab={activeTab}
            fullWidthBorder
            fullWidth={activeTab === 'logs'}
            showToolbarBottomBorder={isLogsTabLayout}
            showFilters={activeTab === 'logs' || activeTab === 'domains'}
            filterTrigger={
              activeTab === 'logs' || activeTab === 'domains' ? (
                <FiltersPopover
                  open={filtersOpen}
                  onOpenChange={setFiltersOpen}
                  columns={siteFilterColumns}
                  filterMap={siteFilterMap}
                  onRemoveFilter={removeSiteFilter}
                  onClearAll={clearAllSiteFilters}
                  onApplyFilter={applySiteFilter}
                  resourceLabel={
                    activeTab === 'logs' ? t('logs') : t('domains')
                  }
                  filterScope={`sites.${activeTab}`}
                  onApplyQuery={(queryParam) => {
                    navigate({
                      to: location.pathname,
                      search: (prev) => ({
                        ...(typeof prev === 'object' && prev !== null
                          ? prev
                          : {}),
                        query: queryParam ?? undefined,
                        page: 1,
                      }),
                      replace: true,
                    })
                  }}
                  teamId={project?.teamId}
                />
              ) : undefined
            }
            showRefresh={
              (activeTab === 'logs' || activeTab === 'usage') &&
              hasRefreshHandler
            }
            onRefresh={
              activeTab === 'logs' || activeTab === 'usage'
                ? triggerRefresh
                : undefined
            }
            isRefreshing={
              activeTab === 'usage'
                ? siteUsageRefreshing
                : siteLogsListRefreshing
            }
            createLabel={activeTab === 'domains' ? t('Add domain') : undefined}
            createAnalyticsAction={
              activeTab === 'domains' ? 'create-site-domain' : undefined
            }
            onCreate={
              activeTab === 'domains'
                ? () =>
                    navigate({
                      to: '/projects/$projectId/sites/$siteId/domains/add',
                      params: { projectId: projectId!, siteId: siteId! },
                    })
                : undefined
            }
            beforeCreateButtons={undefined}
            contentAfterBorder={
              buildingAlert || configAlert ? (
                <div>
                  {buildingAlert}
                  {configAlert}
                </div>
              ) : undefined
            }
          />
        </div>
        <div
          className={cn('flex-1 min-h-0', isLogsTabLayout && 'flex flex-col')}
        >
          <DeploymentsToolbarContext.Provider
            value={
              activeTab === 'deployments' ? (
                <>
                  <FiltersPopover
                    open={filtersOpen}
                    onOpenChange={setFiltersOpen}
                    columns={siteFilterColumns}
                    filterMap={siteFilterMap}
                    onRemoveFilter={removeSiteFilter}
                    onClearAll={clearAllSiteFilters}
                    onApplyFilter={applySiteFilter}
                    resourceLabel={t('deployments')}
                    filterScope="sites.deployments"
                    onApplyQuery={(queryParam) => {
                      navigate({
                        to: location.pathname,
                        search: (prev) => ({
                          ...(typeof prev === 'object' && prev !== null
                            ? prev
                            : {}),
                          query: queryParam ?? undefined,
                          page: 1,
                        }),
                        replace: true,
                      })
                    }}
                    teamId={project?.teamId}
                  />
                  <CreateDeploymentDropdown
                    onSelectGit={() => setGitDeployOpen(true)}
                    onSelectCli={() => setCliDeployOpen(true)}
                    onSelectManual={() => setManualDeployOpen(true)}
                  />
                </>
              ) : null
            }
          >
            <Outlet />
          </DeploymentsToolbarContext.Provider>
        </div>
        {site && siteId && projectId && (
          <>
            <CreateGitDeploymentModal
              open={gitDeployOpen}
              onOpenChange={setGitDeployOpen}
              resourceType="site"
              projectId={projectId}
              resourceId={siteId}
              resource={site}
            />
            <CreateCliDeploymentModal
              open={cliDeployOpen}
              onOpenChange={setCliDeployOpen}
              resourceType="site"
              projectId={projectId}
              resourceId={siteId}
              siteBuildConfig={{
                framework: site.framework,
                buildCommand: site.buildCommand,
                installCommand: site.installCommand,
                startCommand: site.startCommand,
                outputDirectory: site.outputDirectory,
              }}
            />
            <CreateManualDeploymentModal
              open={manualDeployOpen}
              onOpenChange={setManualDeployOpen}
              resourceType="site"
              projectId={projectId}
              resourceId={siteId}
            />
          </>
        )}
      </div>

      {/* Cancel build confirmation */}
      <Dialog
        open={cancelBuildDialogOpen}
        onOpenChange={setCancelBuildDialogOpen}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Cancel build')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Stop the current deployment? You can deploy again later.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            {activeDeployment && (
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
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
              {t('Cancel build')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {activeDeployment && (
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
              <DeploymentInfo deployment={activeDeployment} showStatus={true} />
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
    </CreateDeploymentProvider>
  )
}
