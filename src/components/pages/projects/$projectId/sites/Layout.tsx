import { useState, useMemo, useEffect } from 'react'
import {
  Outlet,
  useParams,
  useNavigate,
  useLocation,
} from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import {
  useProjectSite,
  useSiteDeployment,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowSiteSettingsTab } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Info, ArrowLeft } from 'lucide-react'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import { toast } from 'sonner'
import { CreateDeploymentDropdown } from '../shared/CreateDeploymentDropdown'
import { CreateGitDeploymentModal } from '../shared/CreateGitDeploymentModal'
import { CreateCliDeploymentModal } from '../shared/CreateCliDeploymentModal'
import { CreateManualDeploymentModal } from '../shared/CreateManualDeploymentModal'
import { CreateDeploymentProvider } from '../shared/CreateDeploymentContext'

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

  const handleFilterClick = () => {
    // TODO: Open filters dialog
    toast.info('Filters coming soon')
  }

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

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'deployments',
        label: 'Deployments',
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'logs',
        label: 'Logs',
        to: '/projects/$projectId/sites/$siteId/logs',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'domains',
        label: 'Domains',
        to: '/projects/$projectId/sites/$siteId/domains',
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
          showFilters={activeTab === 'logs'}
          onFilterClick={activeTab === 'logs' ? handleFilterClick : undefined}
          showRefresh={activeTab === 'logs' && hasRefreshHandler}
          onRefresh={activeTab === 'logs' ? triggerRefresh : undefined}
          isRefreshing={isRefreshing}
          createLabel={
            activeTab === 'domains' ? 'Add domain' : undefined
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
          beforeCreateButtons={
            activeTab === 'deployments' ? (
              <CreateDeploymentDropdown
                onSelectGit={() => setGitDeployOpen(true)}
                onSelectCli={() => setCliDeployOpen(true)}
                onSelectManual={() => setManualDeployOpen(true)}
              />
            ) : undefined
          }
          contentAfterBorder={
            isBuilding ? (
              <div className="border-b border-border bg-blue-500/5">
                <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                  <Alert
                    variant="default"
                    className="border-blue-500/30 bg-transparent"
                  >
                    <Info className="h-4 w-4 text-blue-500" />
                    <AlertDescription className="text-[12px] text-blue-600/80 dark:text-blue-400/80">
                      Your site is currently being deployed.
                    </AlertDescription>
                  </Alert>
                </div>
              </div>
            ) : undefined
          }
        />
        <div className="flex-1 min-h-0">
          <Outlet />
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
    </CreateDeploymentProvider>
  )
}
