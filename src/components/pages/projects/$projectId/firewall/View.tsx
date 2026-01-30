import { useMemo, useState } from 'react'
import { useParams, useLocation } from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { RulesTab } from './Rules'
import { AnalyticsTab } from './Analytics'
import { LogsTab } from './Logs'

export function View() {
  const params = useParams({ strict: false })
  const location = useLocation()
  const projectId = params.projectId as string

  const [searchValue, setSearchValue] = useState('')

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const firewallIndex = pathParts.findIndex((part) => part === 'firewall')

    if (firewallIndex >= 0) {
      if (pathParts[firewallIndex + 1]) {
        const tabFromPath = pathParts[firewallIndex + 1]
        if (['analytics', 'logs'].includes(tabFromPath)) {
          return tabFromPath
        }
      }
    }

    return 'rules'
  }, [location.pathname])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'rules',
        label: 'Rules',
        to: '/projects/$projectId/firewall',
        params: { projectId: projectId as string },
      },
      {
        id: 'analytics',
        label: 'Analytics',
        to: '/projects/$projectId/firewall/analytics',
        params: { projectId: projectId as string },
      },
      {
        id: 'logs',
        label: 'Logs',
        to: '/projects/$projectId/firewall/logs',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  const hasSearch = activeTab === 'rules' || activeTab === 'logs'
  const searchPlaceholder = hasSearch
    ? activeTab === 'rules'
      ? 'Search rules...'
      : 'Search logs...'
    : undefined

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Firewall"
        tabs={tabs}
        activeTab={activeTab}
        showFilters={false}
        fullWidthBorder
        searchPlaceholder={searchPlaceholder}
        searchValue={hasSearch ? searchValue : undefined}
        onSearchChange={hasSearch ? setSearchValue : undefined}
        createLabel={activeTab === 'rules' ? 'Create rule' : undefined}
        onCreate={
          activeTab === 'rules'
            ? () => {
                if (typeof window !== 'undefined') {
                  const event = new CustomEvent('firewall-create-rule')
                  window.dispatchEvent(event)
                }
              }
            : undefined
        }
        showRefresh={activeTab === 'analytics'}
        onRefresh={
          activeTab === 'analytics'
            ? () => {
                // Refresh analytics data
                if (typeof window !== 'undefined') {
                  const event = new CustomEvent('firewall-refresh-analytics')
                  window.dispatchEvent(event)
                }
              }
            : undefined
        }
      />

      <div className="flex-1">
        {activeTab === 'rules' && (
          <RulesTab projectId={projectId} searchValue={searchValue} />
        )}
        {activeTab === 'analytics' && <AnalyticsTab projectId={projectId} />}
        {activeTab === 'logs' && (
          <LogsTab projectId={projectId} searchValue={searchValue} />
        )}
      </div>
    </div>
  )
}
