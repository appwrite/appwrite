import { Outlet, useParams, useNavigate } from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import {
  useProjectSite,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowSiteSettingsTab } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMemo } from 'react'
import { useLocation } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { ArrowLeft } from 'lucide-react'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import { toast } from 'sonner'

export function SiteLayout() {
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
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowSiteSettingsTab(access, features)

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

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

  return (
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
      />
      <div className="flex-1 min-h-0">
        <Outlet />
      </div>
    </div>
  )
}
