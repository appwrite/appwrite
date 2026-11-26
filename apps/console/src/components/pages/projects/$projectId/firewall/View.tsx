import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'
import { canSeeProjectNavItem } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import type { FirewallResourceSelection } from '@/lib/firewall/conditions'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { Firewall } from './Firewall'
import { Functions } from './Functions'
import { Sites } from './Sites'

export type FirewallTab = 'api' | 'functions' | 'sites'

/** The API tab manages rules for the project API. */
const API_SCOPE: FirewallResourceSelection = { resourceType: 'api' }

interface ViewProps {
  tab: FirewallTab
}

/**
 * Project Firewall page: the API's rules and traffic, and the functions and
 * sites whose own Firewall tabs hold theirs.
 */
export function View({ tab }: ViewProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const [searchValue, setSearchValue] = useState('')

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showFunctions = canSeeProjectNavItem(access, features, 'functions')
  const showSites = canSeeProjectNavItem(access, features, 'sites')

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'api',
        label: t('API'),
        to: '/projects/$projectId/firewall/',
        params: { projectId },
      },
      ...(showFunctions
        ? [
            {
              id: 'functions',
              label: t('Functions'),
              to: '/projects/$projectId/firewall/functions',
              params: { projectId },
            },
          ]
        : []),
      ...(showSites
        ? [
            {
              id: 'sites',
              label: t('Sites'),
              to: '/projects/$projectId/firewall/sites',
              params: { projectId },
            },
          ]
        : []),
    ],
    [projectId, showFunctions, showSites, t],
  )

  const hasSearch = tab !== 'api'
  const searchPlaceholder =
    tab === 'functions'
      ? t('Search functions...')
      : tab === 'sites'
        ? t('Search sites...')
        : undefined

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Firewall')}
        tabs={tabs}
        activeTab={tab}
        showFilters={false}
        fullWidthBorder
        fullWidth={tab === 'api'}
        searchPlaceholder={searchPlaceholder}
        searchValue={hasSearch ? searchValue : undefined}
        onSearchChange={hasSearch ? setSearchValue : undefined}
      />

      <div className="flex-1">
        {tab === 'api' && (
          <Firewall projectId={projectId} resourceSelection={API_SCOPE} />
        )}
        {tab === 'functions' && (
          <Functions projectId={projectId} searchValue={searchValue} />
        )}
        {tab === 'sites' && (
          <Sites projectId={projectId} searchValue={searchValue} />
        )}
      </div>
    </div>
  )
}
