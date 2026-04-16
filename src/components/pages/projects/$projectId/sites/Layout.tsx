import React, { useState, useMemo, useEffect } from 'react'
import {
  Outlet,
  useParams,
  useNavigate,
  useLocation,
} from '@tanstack/react-router'
import { useQueryClient, useMutation } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import {
  useProjectSite,
  useSiteDeployment,
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
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Info, ArrowLeft } from 'lucide-react'
import { toast } from 'sonner'
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
  const { projectId, siteId } = useParams({ strict: false })
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { isRefreshing, triggerRefresh, hasRefreshHandler } = useRefresh()
  const { data: site } = useProjectSite(projectId, siteId)
  const { data: activeDeployment } = useSiteDeployment(
    projectId,
    siteId,
    site?.deploymentId ?? undefined,
  )
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowSiteSettingsTab(access, features)

  const [cancelBuildDialogOpen, setCancelBuildDialogOpen] = useState(false)
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
      toast.success('Build cancelled')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to cancel build')
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

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const sitesIndex = pathParts.findIndex((part) => part === 'sites')

    if (sitesIndex >= 0 && pathParts[sitesIndex + 2]) {
      const tab = pathParts[sitesIndex + 2]
      if (
        ['deployments', 'logs', 'domains', 'variables', 'settings'].includes(
          tab,
        )
      ) {
        return tab
      }
    }

    // Default to deployments for index route
    return 'deployments'
  }, [location.pathname])

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
        label: 'Deployments',
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'domains',
        label: 'Domains',
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'logs',
        label: 'Logs',
        to: '/projects/$projectId/sites/$siteId/logs',
        params: { projectId: projectId!, siteId: siteId! },
      },
      ...(showSettingsTab
        ? [
            {
              id: 'variables' as const,
              label: 'Variables',
              to: '/projects/$projectId/sites/$siteId/variables',
              params: { projectId: projectId!, siteId: siteId! },
            },
            {
              id: 'settings' as const,
              label: 'Settings',
              to: '/projects/$projectId/sites/$siteId/settings',
              params: { projectId: projectId!, siteId: siteId! },
            },
          ]
        : []),
    ],
    [projectId, siteId, showSettingsTab],
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

  return (
    <CreateDeploymentProvider
      onOpenGit={() => setGitDeployOpen(true)}
      onOpenCli={() => setCliDeployOpen(true)}
      onOpenManual={() => setManualDeployOpen(true)}
    >
      <div className="flex flex-col">
        <ServiceHeader
          title={
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={handleBack}
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <span>{site?.name || 'Site'}</span>
            </div>
          }
          tabs={tabs}
          activeTab={activeTab}
          fullWidthBorder
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
                resourceLabel={activeTab === 'logs' ? 'logs' : 'domains'}
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
          showRefresh={activeTab === 'logs' && hasRefreshHandler}
          onRefresh={activeTab === 'logs' ? triggerRefresh : undefined}
          isRefreshing={isRefreshing}
          createLabel={activeTab === 'domains' ? 'Add domain' : undefined}
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
            isBuilding ? (
              <div className="border-b border-border bg-blue-500/5">
                <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                  <Alert
                    variant="default"
                    className="border-blue-500/30 bg-transparent"
                  >
                    <Info className="h-4 w-4 text-blue-500 shrink-0" />
                    <AlertDescription className="flex flex-1 items-center justify-between gap-3 text-[12px] text-blue-600/80 dark:text-blue-400/80">
                      <span>Your site is currently being deployed.</span>
                      <Button
                        variant="outline"
                        size="sm"
                        className="shrink-0 border-blue-500/40 text-blue-600 hover:bg-blue-500/10 dark:text-blue-400 dark:hover:bg-blue-500/10"
                        onClick={handleCancelBuild}
                        disabled={cancelBuildMutation.isPending}
                      >
                        Cancel build
                      </Button>
                    </AlertDescription>
                  </Alert>
                </div>
              </div>
            ) : undefined
          }
        />
        <div className="flex-1 min-h-0">
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
                    resourceLabel="deployments"
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
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Cancel build</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Stop the current deployment? You can deploy again later.
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
              Keep building
            </Button>
            <Button
              variant="destructive"
              onClick={() => cancelBuildMutation.mutate()}
              disabled={cancelBuildMutation.isPending}
              className="h-9 text-[13px]"
            >
              Cancel build
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </CreateDeploymentProvider>
  )
}
