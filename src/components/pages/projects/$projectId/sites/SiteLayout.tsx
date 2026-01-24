import { Outlet, useParams, useNavigate } from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { useProjectSite } from '@/lib/react-query/hooks'
import { useMemo } from 'react'
import { useLocation } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { ArrowLeft } from 'lucide-react'

export function SiteLayout() {
  const { projectId, siteId } = useParams({ strict: false })
  const location = useLocation()
  const navigate = useNavigate()
  const { data: site } = useProjectSite(projectId, siteId)

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const sitesIndex = pathParts.findIndex((part) => part === 'sites')
    
    if (sitesIndex >= 0 && pathParts[sitesIndex + 2]) {
      const tab = pathParts[sitesIndex + 2]
      if (['deployments', 'logs', 'domains', 'settings'].includes(tab)) {
        return tab
      }
    }
    
    // Default to deployments for index route
    return 'deployments'
  }, [location.pathname])

  const tabs: Tab[] = [
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
    {
      id: 'settings',
      label: 'Settings',
      to: '/projects/$projectId/sites/$siteId/settings',
      params: { projectId: projectId!, siteId: siteId! },
    },
  ]

  return (
    <div className="flex h-full flex-col">
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
      />
      <div className="flex-1 min-h-0">
        <Outlet />
      </div>
    </div>
  )
}
