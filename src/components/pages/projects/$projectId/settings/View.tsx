import { useMemo, useState } from 'react'
import { useParams, useLocation } from '@tanstack/react-router'
import { useProject, useOrganizationScopes } from '@/lib/react-query/hooks'
import {
  canWriteDomains,
  canWriteWebhooks,
  canCreateMigration,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { ProjectSettingsOverview } from './Overview'
import { Domains } from './Domains'
import { Webhooks } from './Webhooks'
import { Migrations } from './Migrations'
import { SMTP } from './SMTP'
import { Variables } from './Variables'
import type { Models } from '@appwrite.io/console'

export interface SettingsViewProps {
  /** Prefetched migrations from route loader; avoids loading spinner on first paint */
  initialMigrationsData?: { migrations: Models.Migration[]; total: number }
}

export function View({ initialMigrationsData }: SettingsViewProps = {}) {
  const t = useT()
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
          ['domains', 'webhooks', 'migrations', 'smtp', 'variables'].includes(
            tabFromPath,
          )
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
        label: t('Overview'),
        to: '/projects/$projectId/settings/',
        params: { projectId: projectId as string },
      },
      {
        id: 'domains',
        label: t('Custom domains'),
        to: '/projects/$projectId/settings/domains',
        params: { projectId: projectId as string },
      },
      {
        id: 'variables',
        label: t('Variables'),
        to: '/projects/$projectId/settings/variables',
        params: { projectId: projectId as string },
      },
      {
        id: 'webhooks',
        label: t('Webhooks'),
        to: '/projects/$projectId/settings/webhooks',
        params: { projectId: projectId as string },
      },
      {
        id: 'migrations',
        label: t('Migrations'),
        to: '/projects/$projectId/settings/migrations',
        params: { projectId: projectId as string },
      },
      {
        id: 'smtp',
        label: t('SMTP'),
        to: '/projects/$projectId/settings/smtp',
        params: { projectId: projectId as string },
      },
    ],
    [projectId, t],
  )

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noDomainsPermission = !canWriteDomains(access, features)
  const noWebhooksPermission = !canWriteWebhooks(access, features)
  const noMigrationsPermission = !canCreateMigration(access, features)
  const createDisabled =
    (activeTab === 'domains' && noDomainsPermission) ||
    (activeTab === 'webhooks' && noWebhooksPermission) ||
    (activeTab === 'migrations' && noMigrationsPermission)
  const createDisabledTooltip =
    activeTab === 'domains' && noDomainsPermission
      ? t("You don't have permission to add domains.")
      : activeTab === 'webhooks' && noWebhooksPermission
        ? t("You don't have permission to create webhooks.")
        : activeTab === 'migrations' && noMigrationsPermission
          ? t("You don't have permission to create migrations.")
          : undefined

  // Determine search and create props based on active tab
  const hasSearch =
    activeTab !== 'overview' &&
    activeTab !== 'smtp' &&
    activeTab !== 'variables'
  const searchPlaceholder = hasSearch
    ? activeTab === 'domains'
      ? t('Search domains...')
      : activeTab === 'webhooks'
        ? t('Search webhooks...')
        : activeTab === 'migrations'
          ? t('Search migrations...')
          : `Search ${activeTab}...`
    : undefined
  const createLabel =
    activeTab === 'domains'
      ? t('Add domain')
      : activeTab === 'webhooks'
        ? t('Create webhook')
        : activeTab === 'migrations'
          ? t('Import data')
          : undefined
  const createTo =
    activeTab === 'domains'
      ? '/projects/$projectId/settings/domains/add'
      : activeTab === 'migrations'
        ? '/projects/$projectId/settings/migrations/import'
        : undefined
  const createParams =
    activeTab === 'domains' || activeTab === 'migrations'
      ? { projectId }
      : undefined
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
        title={t('Settings')}
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
        createAnalyticsAction={
          activeTab === 'webhooks'
            ? 'create-webhook'
            : activeTab === 'domains'
              ? 'add-project-domain'
              : activeTab === 'migrations'
                ? 'import-data'
                : undefined
        }
        onCreate={handleCreate}
        createDisabled={createDisabled}
        createDisabledTooltip={createDisabledTooltip}
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
        {activeTab === 'migrations' && (
          <Migrations
            projectId={projectId}
            initialData={initialMigrationsData}
          />
        )}
        {activeTab === 'smtp' && <SMTP projectId={projectId} />}
        {activeTab === 'variables' && <Variables />}
      </div>
    </div>
  )
}
