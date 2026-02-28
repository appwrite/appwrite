import { useMemo, useState } from 'react'
import { useParams, useLocation } from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { ProjectSettingsOverview } from './Overview'
import { Domains } from './Domains'
import { Webhooks } from './Webhooks'
import { Migrations } from './Migrations'
import { SMTP } from './SMTP'

export function View() {
  const params = useParams({ strict: false })
  const location = useLocation()
  const projectId = params.projectId as string

  const [searchValue, setSearchValue] = useState('')

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const settingsIndex = pathParts.findIndex((part) => part === 'settings')

    if (settingsIndex >= 0) {
      // Check if there's a tab segment after 'settings'
      // pathParts structure: ['projects', 'projectId', 'settings', 'tab?']
      if (pathParts[settingsIndex + 1]) {
        const tabFromPath = pathParts[settingsIndex + 1]
        if (
          ['domains', 'webhooks', 'migrations', 'smtp'].includes(tabFromPath)
        ) {
          return tabFromPath
        }
      }
    }

    // Default to overview for index route (/projects/:projectId/settings or /projects/:projectId/settings/)
    return 'overview'
  }, [location.pathname])

  // Update tabs with route paths
  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'overview',
        label: 'Overview',
        to: '/projects/$projectId/settings/',
        params: { projectId: projectId as string },
      },
      {
        id: 'domains',
        label: 'Custom domains',
        to: '/projects/$projectId/settings/domains',
        params: { projectId: projectId as string },
      },
      {
        id: 'webhooks',
        label: 'Webhooks',
        to: '/projects/$projectId/settings/webhooks',
        params: { projectId: projectId as string },
      },
      {
        id: 'migrations',
        label: 'Migrations',
        to: '/projects/$projectId/settings/migrations',
        params: { projectId: projectId as string },
      },
      {
        id: 'smtp',
        label: 'SMTP',
        to: '/projects/$projectId/settings/smtp',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  // Determine search and create props based on active tab
  const hasSearch = activeTab !== 'overview' && activeTab !== 'smtp'
  const searchPlaceholder = hasSearch ? `Search ${activeTab}...` : undefined
  const createLabel =
    activeTab === 'domains'
      ? 'Add domain'
      : activeTab === 'webhooks'
        ? 'Create webhook'
        : undefined
  const createTo =
    activeTab === 'domains' ? '/projects/$projectId/settings/domains/add' : undefined
  const createParams =
    activeTab === 'domains' ? { projectId } : undefined
  const handleCreate = useMemo(() => {
    if (activeTab === 'webhooks') {
      return () => {
        if (typeof window !== 'undefined') {
          const event = new CustomEvent('settings-create-webhook')
          window.dispatchEvent(event)
        }
      }
    }
    return undefined
  }, [activeTab])

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Settings"
        tabs={tabs}
        activeTab={activeTab}
        showFilters={false}
        fullWidthBorder
        searchPlaceholder={searchPlaceholder}
        searchValue={hasSearch ? searchValue : undefined}
        onSearchChange={hasSearch ? setSearchValue : undefined}
        createLabel={createLabel}
        createTo={createTo}
        createParams={createParams}
        onCreate={handleCreate}
      />

      <div className="flex-1">
        {activeTab === 'overview' && (
          <ProjectSettingsOverview projectId={projectId} />
        )}
        {activeTab === 'domains' && (
          <Domains projectId={projectId} searchValue={searchValue} />
        )}
        {activeTab === 'webhooks' && (
          <Webhooks projectId={projectId} searchValue={searchValue} />
        )}
        {activeTab === 'migrations' && <Migrations projectId={projectId} />}
        {activeTab === 'smtp' && <SMTP projectId={projectId} />}
      </div>
    </div>
  )
}
