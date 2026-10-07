import { useMemo } from 'react'
import { Outlet, useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { POSTGRES_DATABASE_SETTINGS_CARD_INDEX } from '@/lib/settings-search/postgres-database-settings-cards'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import {
  POSTGRES_DATABASE_SETTINGS_NAV,
  type PostgresDatabaseSettingsNavItem,
  type PostgresDatabaseSettingsPathSuffix,
} from './nav'

type PostgresSettingsPath =
  | '/projects/$projectId/databases/postgres/$databaseId/settings'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/compute'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/replication'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/network'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/pitr'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/storage'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/extensions'
  | '/projects/$projectId/databases/postgres/$databaseId/settings/maintenance'

const POSTGRES_SETTINGS_TO: Record<
  PostgresDatabaseSettingsPathSuffix,
  PostgresSettingsPath
> = {
  '': '/projects/$projectId/databases/postgres/$databaseId/settings',
  compute:
    '/projects/$projectId/databases/postgres/$databaseId/settings/compute',
  replication:
    '/projects/$projectId/databases/postgres/$databaseId/settings/replication',
  network:
    '/projects/$projectId/databases/postgres/$databaseId/settings/network',
  pitr: '/projects/$projectId/databases/postgres/$databaseId/settings/pitr',
  storage:
    '/projects/$projectId/databases/postgres/$databaseId/settings/storage',
  extensions:
    '/projects/$projectId/databases/postgres/$databaseId/settings/extensions',
  maintenance:
    '/projects/$projectId/databases/postgres/$databaseId/settings/maintenance',
}

function useActiveSettingsSection(pathname: string): string {
  return useMemo(() => {
    const parts = pathname.split('/').filter(Boolean)
    const i = parts.indexOf('settings')
    const next = i >= 0 ? parts[i + 1] : undefined
    if (!next) return 'general'
    if (
      [
        'compute',
        'replication',
        'network',
        'pitr',
        'storage',
        'extensions',
        'maintenance',
      ].includes(next)
    ) {
      return next
    }
    return 'general'
  }, [pathname])
}

function toForItem(
  pathSuffix: PostgresDatabaseSettingsPathSuffix,
): PostgresSettingsPath {
  return POSTGRES_SETTINGS_TO[pathSuffix]
}

export function PostgresDatabaseSettingsShell() {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()
  const { projectId, databaseId } = useParams({ strict: false })
  const { features } = useConsoleProfile()
  const activeSection = useActiveSettingsSection(location.pathname)

  const navItems = useMemo(
    () =>
      POSTGRES_DATABASE_SETTINGS_NAV.filter(
        (item) =>
          item.id !== 'pitr' || features.databaseBackups,
      ),
    [features.databaseBackups],
  )

  const params = { projectId: projectId!, databaseId: databaseId! }

  const layoutNavItems = useMemo(
    () =>
      navItems.map((item: PostgresDatabaseSettingsNavItem) => ({
        id: item.id,
        label: t(item.label),
        icon: item.icon,
        keywords: item.keywords,
        to: toForItem(item.pathSuffix),
        params,
      })),
    [navItems, projectId, databaseId, t],
  )

  return (
    <SettingsLayoutShell
      navItems={layoutNavItems}
      activeSectionId={activeSection}
      cardIndex={POSTGRES_DATABASE_SETTINGS_CARD_INDEX}
      onNavigateToSection={(sectionId) => {
        const item = navItems.find((n) => n.id === sectionId)
        if (!item) return
        navigate({
          to: toForItem(item.pathSuffix),
          params,
        })
      }}
    >
      <Outlet />
    </SettingsLayoutShell>
  )
}
