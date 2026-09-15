import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Outlet,
  useLocation,
  useNavigate,
  useParams,
} from '@tanstack/react-router'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { DATABASE_SETTINGS_CARD_INDEX } from '@/lib/settings-search/database-settings-cards'
import { useT } from '@/lib/i18n/translate'
import {
  type DatabaseSettingsNavItem,
  type DatabaseSettingsPathSuffix,
  visibleDatabaseSettingsCards,
  visibleDatabaseSettingsNav,
} from './nav'
import {
  dedicatedDatabaseByIdQueryOptions,
  useProjectDatabase,
} from '@/lib/react-query/hooks'
import { canConfigureDedicatedReplication } from '@/lib/databases/database-compute'
import {
  isCloudDedicatedDatabasesEnabled,
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'

type DatabaseSettingsPath =
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings'
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings/specification'
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings/replication'
  | '/projects/$projectId/databases/$dbKind/$databaseId/settings/security'

const DATABASE_SETTINGS_TO: Record<
  DatabaseSettingsPathSuffix,
  DatabaseSettingsPath
> = {
  '': '/projects/$projectId/databases/$dbKind/$databaseId/settings',
  specification:
    '/projects/$projectId/databases/$dbKind/$databaseId/settings/specification',
  replication:
    '/projects/$projectId/databases/$dbKind/$databaseId/settings/replication',
  security:
    '/projects/$projectId/databases/$dbKind/$databaseId/settings/security',
}

function useActiveSettingsSection(pathname: string): string {
  return useMemo(() => {
    const parts = pathname.split('/').filter(Boolean)
    const i = parts.indexOf('settings')
    const next = i >= 0 ? parts[i + 1] : undefined
    if (!next) return 'general'
    if (['specification', 'replication', 'security'].includes(next)) return next
    return 'general'
  }, [pathname])
}

function toForItem(
  pathSuffix: DatabaseSettingsPathSuffix,
): DatabaseSettingsPath {
  return DATABASE_SETTINGS_TO[pathSuffix]
}

export function DatabaseSettingsShell() {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()
  const {
    projectId,
    dbKind: rawDbKind,
    databaseId,
  } = useParams({
    strict: false,
  })
  const dbKind = (
    isDatabaseRouteKind(rawDbKind ?? '') ? rawDbKind : 'tablesdb'
  ) as DatabaseRouteKind
  const activeSection = useActiveSettingsSection(location.pathname)

  const { database: productDatabase } = useProjectDatabase(
    projectId,
    databaseId,
    dbKind,
  )
  const { data: dedicated } = useQuery(
    dedicatedDatabaseByIdQueryOptions(projectId, databaseId, {
      type: 'product',
      dbKind,
    }),
  )

  const showReplication = canConfigureDedicatedReplication(
    {
      $id: productDatabase?.$id,
      name: productDatabase?.name,
      databaseType: dbKind,
      status: (productDatabase as { status?: string | null } | null)?.status,
      replicas: (productDatabase as { replicas?: number | null } | null)
        ?.replicas,
      specification: (
        productDatabase as { specification?: string | null } | null
      )?.specification,
    },
    dedicated,
  )

  const params = {
    projectId: projectId!,
    dbKind: dbKind!,
    databaseId: databaseId!,
  }

  const isCloud = isCloudDedicatedDatabasesEnabled()

  const visibleNav = useMemo(
    () => visibleDatabaseSettingsNav({ isCloud, showReplication }),
    [isCloud, showReplication],
  )

  const cardIndex = useMemo(
    () =>
      visibleDatabaseSettingsCards(DATABASE_SETTINGS_CARD_INDEX, {
        isCloud,
        showReplication,
      }),
    [isCloud, showReplication],
  )

  const layoutNavItems = useMemo(
    () =>
      visibleNav.map((item: DatabaseSettingsNavItem) => ({
        id: item.id,
        label: t(item.label),
        icon: item.icon,
        keywords: item.keywords,
        to: toForItem(item.pathSuffix),
        params,
      })),
    [projectId, dbKind, databaseId, t, visibleNav],
  )

  return (
    <SettingsLayoutShell
      navItems={layoutNavItems}
      activeSectionId={activeSection}
      cardIndex={cardIndex}
      onNavigateToSection={(sectionId) => {
        const item = visibleNav.find((n) => n.id === sectionId)
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
