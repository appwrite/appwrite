import { useMemo } from 'react'
import { Outlet, useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { MYSQL_DATABASE_SETTINGS_CARD_INDEX } from '@/lib/settings-search/mysql-database-settings-cards'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import {
  MYSQL_DATABASE_SETTINGS_NAV,
  type MysqlDatabaseSettingsNavItem,
  type MysqlDatabaseSettingsPathSuffix,
} from './nav'

type MysqlSettingsPath =
  | '/projects/$projectId/databases/mysql/$databaseId/settings'
  | '/projects/$projectId/databases/mysql/$databaseId/settings/compute'
  | '/projects/$projectId/databases/mysql/$databaseId/settings/replication'
  | '/projects/$projectId/databases/mysql/$databaseId/settings/network'
  | '/projects/$projectId/databases/mysql/$databaseId/settings/pitr'
  | '/projects/$projectId/databases/mysql/$databaseId/settings/storage'
  | '/projects/$projectId/databases/mysql/$databaseId/settings/maintenance'

const MYSQL_SETTINGS_TO: Record<
  MysqlDatabaseSettingsPathSuffix,
  MysqlSettingsPath
> = {
  '': '/projects/$projectId/databases/mysql/$databaseId/settings',
  compute:
    '/projects/$projectId/databases/mysql/$databaseId/settings/compute',
  replication:
    '/projects/$projectId/databases/mysql/$databaseId/settings/replication',
  network:
    '/projects/$projectId/databases/mysql/$databaseId/settings/network',
  pitr: '/projects/$projectId/databases/mysql/$databaseId/settings/pitr',
  storage:
    '/projects/$projectId/databases/mysql/$databaseId/settings/storage',
  maintenance:
    '/projects/$projectId/databases/mysql/$databaseId/settings/maintenance',
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
        'maintenance',
      ].includes(next)
    ) {
      return next
    }
    return 'general'
  }, [pathname])
}

function toForItem(
  pathSuffix: MysqlDatabaseSettingsPathSuffix,
): MysqlSettingsPath {
  return MYSQL_SETTINGS_TO[pathSuffix]
}

export function MysqlDatabaseSettingsShell() {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()
  const { projectId, databaseId } = useParams({ strict: false })
  const { features } = useConsoleProfile()
  const activeSection = useActiveSettingsSection(location.pathname)

  const navItems = useMemo(
    () =>
      MYSQL_DATABASE_SETTINGS_NAV.filter(
        (item) =>
          item.id !== 'pitr' || features.databaseBackups,
      ),
    [features.databaseBackups],
  )

  const params = { projectId: projectId!, databaseId: databaseId! }

  const layoutNavItems = useMemo(
    () =>
      navItems.map((item: MysqlDatabaseSettingsNavItem) => ({
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
      cardIndex={MYSQL_DATABASE_SETTINGS_CARD_INDEX}
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
