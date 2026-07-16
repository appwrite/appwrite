import { useMemo } from 'react'
import { Outlet, useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { DATABASE_SETTINGS_CARD_INDEX } from '@/lib/settings-search/database-settings-cards'
import { useT } from '@/lib/i18n/translate'
import {
  DATABASE_SETTINGS_NAV,
  type DatabaseSettingsNavItem,
  type DatabaseSettingsPathSuffix,
} from './nav'

type DatabaseSettingsPath =
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings'
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings/specification'
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings/security'

const DATABASE_SETTINGS_TO: Record<
  DatabaseSettingsPathSuffix,
  DatabaseSettingsPath
> = {
  '': '/projects/$projectId/databases/$dbKind/$databaseId/settings',
  specification:
    '/projects/$projectId/databases/$dbKind/$databaseId/settings/specification',
  security:
    '/projects/$projectId/databases/$dbKind/$databaseId/settings/security',
}

function useActiveSettingsSection(pathname: string): string {
  return useMemo(() => {
    const parts = pathname.split('/').filter(Boolean)
    const i = parts.indexOf('settings')
    const next = i >= 0 ? parts[i + 1] : undefined
    if (!next) return 'general'
    if (['specification', 'security'].includes(next)) return next
    return 'general'
  }, [pathname])
}

function toForItem(pathSuffix: DatabaseSettingsPathSuffix): DatabaseSettingsPath {
  return DATABASE_SETTINGS_TO[pathSuffix]
}

export function DatabaseSettingsShell() {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()
  const { projectId, dbKind, databaseId } = useParams({ strict: false })
  const activeSection = useActiveSettingsSection(location.pathname)

  const params = {
    projectId: projectId!,
    dbKind: dbKind!,
    databaseId: databaseId!,
  }

  const layoutNavItems = useMemo(
    () =>
      DATABASE_SETTINGS_NAV.map((item: DatabaseSettingsNavItem) => ({
        id: item.id,
        label: t(item.label),
        icon: item.icon,
        keywords: item.keywords,
        to: toForItem(item.pathSuffix),
        params,
      })),
    [projectId, dbKind, databaseId, t],
  )

  return (
    <SettingsLayoutShell
      navItems={layoutNavItems}
      activeSectionId={activeSection}
      cardIndex={DATABASE_SETTINGS_CARD_INDEX}
      onNavigateToSection={(sectionId) => {
        const item = DATABASE_SETTINGS_NAV.find((n) => n.id === sectionId)
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
