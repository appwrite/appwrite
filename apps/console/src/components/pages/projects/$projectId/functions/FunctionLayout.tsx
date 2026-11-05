import { useMemo } from 'react'
import {
  useParams,
  useLocation,
  Outlet,
  useNavigate,
} from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { useProjectFunction } from '@/lib/react-query/hooks'
import { ArrowLeft, AlertCircle } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { toast } from 'sonner'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import { useT } from '@/lib/i18n/translate'

export function FunctionLayout() {
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
  const { isRefreshing, triggerRefresh, hasRefreshHandler } = useRefresh()

  const { data: func, isLoading } = useProjectFunction(projectId, functionId)

  // Get search value from URL
  const searchParams = new URLSearchParams(
    typeof location.search === 'string' ? location.search : '',
  )
  const domainsSearchValue = searchParams.get('search') || ''

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const functionIndex = pathParts.findIndex(
      (part, idx) =>
        part === 'functions' &&
        pathParts[idx + 1] &&
        pathParts[idx + 1] !== 'templates',
    )

    if (functionIndex >= 0) {
      // Check if there's a tab segment after function ID
      // Pattern: /projects/:projectId/functions/:functionId/:tab?
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

    // Default to deployments for index route
    return 'deployments'
  }, [location.pathname])

  const tabs: Tab[] = useMemo(
    () => [
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
      {
        id: 'variables',
        label: t('Variables'),
        to: '/projects/$projectId/functions/$functionId/variables',
        params: {
          projectId: projectId as string,
          functionId: functionId as string,
        },
      },
      {
        id: 'security',
        label: t('Security'),
        to: '/projects/$projectId/functions/$functionId/security',
        params: {
          projectId: projectId as string,
          functionId: functionId as string,
        },
      },
      {
        id: 'settings',
        label: t('Settings'),
        to: '/projects/$projectId/functions/$functionId/settings',
        params: {
          projectId: projectId as string,
          functionId: functionId as string,
        },
      },
    ],
    [projectId, functionId, t],
  )

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

  const handleCreateDeployment = () => {
    // TODO: Open create deployment dialog
    toast.info(t('Deployment creation coming soon'))
  }

  const handleCreateExecution = () => {
    if (!func?.deploymentId) {
      toast.error(
        t('Execution cannot be created because there is no active deployment'),
      )
      return
    }
    // TODO: Open execution creation modal
    toast.info(t('Execution creation coming soon'))
  }

  const handleFilterClick = () => {
    // TODO: Open filters dialog
    toast.info(t('Filters coming soon'))
  }

  const handleAddDomain = () => {
    // TODO: Open add domain dialog
    toast.info(t('Add domain functionality coming soon'))
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
                onClick={() => {
                  toast.info(t('Redeploy functionality coming soon'))
                }}
              >
                {t('Redeploy')}
              </Button>
            </div>
          </Alert>
        </div>
      </div>
    ) : undefined

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              asChild
              className="h-7 w-7 p-0"
              aria-label={t('Back to functions')}
            >
              <Link
                to="/projects/$projectId/functions"
                params={{ projectId: projectId! }}
              >
                <ArrowLeft className="h-4 w-4" />
              </Link>
            </Button>
            <span className="truncate">{func.name || t('Unnamed Function')}</span>
            <CopyableId id={func.$id} size="xs" className="shrink-0" />
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        searchPlaceholder={
          activeTab === 'domains' ? t('Search domain...') : undefined
        }
        searchValue={activeTab === 'domains' ? domainsSearchValue : undefined}
        onSearchChange={
          activeTab === 'domains' ? handleDomainsSearchChange : undefined
        }
        showFilters={activeTab === 'deployments' || activeTab === 'executions'}
        onFilterClick={
          activeTab === 'deployments' || activeTab === 'executions'
            ? handleFilterClick
            : undefined
        }
        showRefresh={activeTab === 'executions' && hasRefreshHandler}
        onRefresh={activeTab === 'executions' ? triggerRefresh : undefined}
        isRefreshing={isRefreshing}
        createLabel={
          activeTab === 'deployments'
            ? t('Create deployment')
            : activeTab === 'executions'
              ? t('Create execution')
              : activeTab === 'domains'
                ? t('Add domain')
                : undefined
        }
        onCreate={
          activeTab === 'deployments'
            ? handleCreateDeployment
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
      <div className="flex-1">
        <Outlet />
      </div>
    </div>
  )
}
