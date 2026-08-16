import React, { useMemo, useState, useEffect } from 'react'
import {
  useParams,
  useLocation,
  Outlet,
  useNavigate,
} from '@tanstack/react-router'
import { useMutation, useQueryClient, useIsFetching } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { DeploymentInfo } from '@/components/global/shared/DeploymentInfo'
import type { Models } from '@appwrite.io/console'
import {
  useProjectFunction,
  useFunctionDeployment,
  functionDeploymentQueryOptions,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { canShowFunctionSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { AlertCircle, ArrowLeft } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
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
import { CreateExecutionDrawer } from './CreateExecutionDrawer'
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
      <FunctionLayoutContent />
    </RefreshProvider>
  )
}

function FunctionLayoutContent() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { triggerRefresh, hasRefreshHandler } = useRefresh()
  const executionsListRefreshing =
    useIsFetching({
      queryKey: ['executions', 'function', projectId, functionId],
    }) > 0

  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const functionIndex = pathParts.findIndex(
      (part, idx) =>
        part === 'functions' &&
        pathParts[idx + 1] &&
        pathParts[idx + 1] !== 'templates',
    )

    if (functionIndex >= 0) {
      if (pathParts[functionIndex + 2]) {
        const tabFromPath = pathParts[functionIndex + 2]
        if (
          [
            'deployments',
            'executions',
            'domains',
            'variables',
            'security',
            'settings',
          ].includes(tabFromPath)
        ) {
          return tabFromPath
        }
      }
    }

    return 'deployments'
  }, [location.pathname])

  const { data: func, isLoading } = useProjectFunction(projectId, functionId)
  const activeDeploymentId = func?.deploymentId
  const isExecutionsTab = activeTab === 'executions'
  const cachedActiveDeployment =
    projectId && functionId && activeDeploymentId
      ? queryClient.getQueryData<Models.Deployment>(
          functionDeploymentQueryOptions(
            projectId,
            functionId,
            activeDeploymentId,
          ).queryKey,
        )
      : undefined
  const { data: activeDeploymentFromQuery } = useFunctionDeployment(
    projectId,
    functionId,
    isExecutionsTab ? undefined : activeDeploymentId,
  )
  const activeDeployment = activeDeploymentFromQuery ?? cachedActiveDeployment

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSecuritySettings = canShowFunctionSecuritySettings(access, features)

  // Get search value from URL (location.search may be string or parsed object in TanStack Router)
  const domainsSearchValue = (() => {
    const search = location.search
    if (typeof search === 'object' && search !== null && 'search' in search) {
      return (search as { search?: string }).search ?? ''
    }
    const params = new URLSearchParams(typeof search === 'string' ? search : '')
    return params.get('search') || ''
  })()

  const [filtersOpen, setFiltersOpen] = useState(false)
  const functionFilterMap = useMemo(() => {
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

  const functionFilterColumns = useMemo(() => {
    if (activeTab === 'deployments') return deploymentsFilterColumns
    if (activeTab === 'executions') return executionsFilterColumns
    if (activeTab === 'domains') return proxyRulesFilterColumns
    return deploymentsFilterColumns
  }, [activeTab])

  const applyFunctionFilter = (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(functionFilterMap)
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
  const removeFunctionFilter = (key: CompactFilterKey) => {
    const newMap = new Map(functionFilterMap)
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
  const clearAllFunctionFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: undefined,
      }),
      replace: true,
    })
  }

  const tabs: Tab[] = useMemo(() => {
    const base: Tab[] = [
      {
        id: 'deployments',
        label: t('Deployments'),
        to: '/projects/$projectId/functions/$functionId',
        params: {
          projectId: projectId as string,
          functionId: functionId as string,
        },
      },
      {
        id: 'domains',
        label: t('Domains'),
        to: '/projects/$projectId/functions/$functionId/domains',
        params: {
          projectId: projectId as string,
          functionId: functionId as string,
        },
      },
      {
        id: 'executions',
        label: t('Executions'),
        to: '/projects/$projectId/functions/$functionId/executions',
        params: {
          projectId: projectId as string,
          functionId: functionId as string,
        },
      },
      ...(showSecuritySettings
        ? [
            {
              id: 'variables' as const,
              label: t('Variables'),
              to: '/projects/$projectId/functions/$functionId/variables',
              params: {
                projectId: projectId as string,
                functionId: functionId as string,
              },
            },
            {
              id: 'security' as const,
              label: t('Security'),
              to: '/projects/$projectId/functions/$functionId/security',
              params: {
                projectId: projectId as string,
                functionId: functionId as string,
              },
            },
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/functions/$functionId/settings',
              params: {
                projectId: projectId as string,
                functionId: functionId as string,
              },
            },
          ]
        : []),
    ]
    return base
  }, [projectId, functionId, showSecuritySettings, t])

  // Redirect from variables/security/settings when user lacks permission
  useEffect(() => {
    if (showSecuritySettings || !projectId || !functionId) return
    if (
      activeTab === 'variables' ||
      activeTab === 'security' ||
      activeTab === 'settings'
    ) {
      navigate({
        to: '/projects/$projectId/functions/$functionId',
        params: { projectId, functionId },
        replace: true,
      })
    }
  }, [showSecuritySettings, activeTab, projectId, functionId, navigate])

  const handleDomainsSearchChange = (value: string) => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        search: value || undefined,
      }),
      replace: true,
    })
  }

  const [gitDeployOpen, setGitDeployOpen] = useState(false)
  const [cliDeployOpen, setCliDeployOpen] = useState(false)
  const [manualDeployOpen, setManualDeployOpen] = useState(false)
  const [executeDrawerOpen, setExecuteDrawerOpen] = useState(false)
  const [redeployDialogOpen, setRedeployDialogOpen] = useState(false)

  const redeployMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !functionId || !activeDeployment) {
        throw new Error(
          'Project ID, Function ID, and Deployment ID are required',
        )
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.createDuplicateDeployment({
        functionId,
        deploymentId: activeDeployment.$id,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['deployments', 'project', projectId, functionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      toast.success(t('Deployment rebuild started'))
      setRedeployDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to redeploy'))
    },
  })

  const handleCreateExecution = () => {
    if (!func?.deploymentId) {
      toast.error(
        t('Execution cannot be created because there is no active deployment'),
      )
      return
    }
    setExecuteDrawerOpen(true)
  }

  const handleAddDomain = () => {
    navigate({
      to: '/projects/$projectId/functions/$functionId/domains/add',
      params: { projectId: projectId!, functionId: functionId! },
    })
  }

  if (isLoading) {
    return (
      <div className="flex flex-col">
        <ServiceHeader title={t('Loading...')} fullWidthBorder />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading function...')}
            </p>
          </div>
        </div>
      </div>
    )
  }

  if (!func) {
    return (
      <div className="flex flex-col">
        <ServiceHeader title={t('Function not found')} fullWidthBorder />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="mb-4 text-[13px] text-muted-foreground">
              {t(
                "The function you're looking for doesn't exist or you don't have access to it.",
              )}
            </p>
            <Button variant="outline" asChild>
              <Link
                to="/projects/$projectId/functions"
                params={{ projectId: projectId! }}
              >
                <ArrowLeft className="me-1.5 h-4 w-4" />
                {t('Back to Functions')}
              </Link>
            </Button>
          </div>
        </div>
      </div>
    )
  }

  // Disabled alert
  const disabledAlert =
    func && func.enabled === false ? (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
          <Alert
            variant="default"
            className="border-amber-500/30 bg-transparent"
          >
            <AlertCircle className="h-4 w-4 text-amber-500" />
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              {t('Function is disabled')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              <span className="inline">
                {t(
                  'This function is disabled and not accessible to end users through the API. Console actions remain available.',
                )}{' '}
                <Link
                  to="/projects/$projectId/functions/$functionId/settings"
                  params={{ projectId: projectId!, functionId: functionId! }}
                  className="font-medium underline hover:no-underline inline"
                >
                  {t('Enable it in the Settings tab')}
                </Link>{' '}
                {t('to make it available to end users.')}
              </span>
            </AlertDescription>
          </Alert>
        </div>
      </div>
    ) : undefined

  // Settings changes alert
  const configAlert =
    !func?.live && func ? (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
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
                      "You've updated function settings, but they won't take effect until you redeploy. The current deployment is still running with the previous settings.",
                    )}
                  </span>
                </AlertDescription>
              </div>
              <Button
                size="sm"
                className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                onClick={() => setRedeployDialogOpen(true)}
                disabled={
                  !func.deploymentId ||
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

  const isExecutionsTabLayout = activeTab === 'executions'

  return (
    <CreateDeploymentProvider
      onOpenGit={() => setGitDeployOpen(true)}
      onOpenCli={() => setCliDeployOpen(true)}
      onOpenManual={() => setManualDeployOpen(true)}
    >
      <div
        className={cn(
          'flex min-h-0 flex-1 flex-col',
          isExecutionsTabLayout && 'h-full',
        )}
      >
        <div
          className={cn(
            isExecutionsTabLayout &&
              'sticky top-0 z-20 shrink-0 bg-background',
          )}
        >
          <ServiceHeader
            title={
            <DetailResourceHeaderTitle
              kind="function"
              label={func.name || t('Unnamed Function')}
              resourceId={func.$id}
              projectId={projectId}
              back={{
                to: '/projects/$projectId/functions',
                params: { projectId: projectId! },
                'aria-label': t('Back to functions'),
              }}
            />
          }
          tabs={tabs}
          activeTab={activeTab}
          fullWidthBorder
          fullWidth={activeTab === 'executions'}
          showToolbarBottomBorder={isExecutionsTabLayout}
          searchPlaceholder={
            activeTab === 'domains' ? t('Search domain...') : undefined
          }
          searchValue={activeTab === 'domains' ? domainsSearchValue : undefined}
          onSearchChange={
            activeTab === 'domains' ? handleDomainsSearchChange : undefined
          }
          showFilters={activeTab === 'executions' || activeTab === 'domains'}
          filterTrigger={
            activeTab === 'executions' || activeTab === 'domains' ? (
              <FiltersPopover
                open={filtersOpen}
                onOpenChange={setFiltersOpen}
                columns={functionFilterColumns}
                filterMap={functionFilterMap}
                onRemoveFilter={removeFunctionFilter}
                onClearAll={clearAllFunctionFilters}
                onApplyFilter={applyFunctionFilter}
                resourceLabel={
                  activeTab === 'executions' ? 'executions' : 'domains'
                }
                filterScope={`functions.${activeTab}`}
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
          showRefresh={activeTab === 'executions' && hasRefreshHandler}
          onRefresh={activeTab === 'executions' ? triggerRefresh : undefined}
          isRefreshing={executionsListRefreshing}
          beforeCreateButtons={undefined}
          createLabel={
            activeTab === 'deployments'
              ? undefined
              : activeTab === 'executions'
                ? t('Create execution')
                : activeTab === 'domains'
                  ? t('Add domain')
                  : undefined
          }
          createAnalyticsAction={
            activeTab === 'executions' ? 'create-execution' : undefined
          }
          onCreate={
            activeTab === 'deployments'
              ? undefined
              : activeTab === 'executions'
                ? handleCreateExecution
                : activeTab === 'domains'
                  ? handleAddDomain
                  : undefined
          }
          createDisabled={activeTab === 'executions' && !func?.deploymentId}
          contentAfterBorder={
            disabledAlert || configAlert ? (
              <div>
                {disabledAlert}
                {configAlert}
              </div>
            ) : undefined
          }
        />
        </div>
        <div
          className={cn(
            'flex-1 min-h-0',
            isExecutionsTabLayout && 'flex flex-col',
          )}
        >
          <DeploymentsToolbarContext.Provider
            value={
              activeTab === 'deployments' ? (
                <>
                  <FiltersPopover
                    open={filtersOpen}
                    onOpenChange={setFiltersOpen}
                    columns={functionFilterColumns}
                    filterMap={functionFilterMap}
                    onRemoveFilter={removeFunctionFilter}
                    onClearAll={clearAllFunctionFilters}
                    onApplyFilter={applyFunctionFilter}
                    resourceLabel="deployments"
                    filterScope="functions.deployments"
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
        {func && functionId && projectId && (
          <>
            <CreateExecutionDrawer
              open={executeDrawerOpen}
              onOpenChange={setExecuteDrawerOpen}
              functionId={functionId}
              func={func}
            />
            <CreateGitDeploymentModal
              open={gitDeployOpen}
              onOpenChange={setGitDeployOpen}
              resourceType="function"
              projectId={projectId}
              resourceId={functionId}
              resource={func}
            />
            <CreateCliDeploymentModal
              open={cliDeployOpen}
              onOpenChange={setCliDeployOpen}
              resourceType="function"
              projectId={projectId}
              resourceId={functionId}
            />
            <CreateManualDeploymentModal
              open={manualDeployOpen}
              onOpenChange={setManualDeployOpen}
              resourceType="function"
              projectId={projectId}
              resourceId={functionId}
            />
          </>
        )}
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
                    "This will create a new build for this deployment using the current function configuration. The original deployment's code will be preserved and used for the new build.",
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
      </div>
    </CreateDeploymentProvider>
  )
}
