import { useMemo } from 'react'
import { Outlet, useLocation, useNavigate, useParams } from '@tanstack/react-router'
import type { LucideIcon } from 'lucide-react'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { FUNCTION_SETTINGS_CARD_INDEX } from '@/lib/settings-search/function-settings-cards'
import { SITE_SETTINGS_CARD_INDEX } from '@/lib/settings-search/site-settings-cards'
import { useT } from '@/lib/i18n/translate'

export type ResourceSettingsNavItem = {
  id: string
  label: string
  /** Path after `.../settings` - use `''` for index */
  pathSuffix: '' | 'git' | 'build' | 'runtime' | 'executions'
  icon: LucideIcon
  keywords: string[]
}

type ShellKind = 'function' | 'site'

type FunctionSettingsPath =
  | '/projects/$projectId/functions/$functionId/settings'
  | '/projects/$projectId/functions/$functionId/settings/git'
  | '/projects/$projectId/functions/$functionId/settings/build'
  | '/projects/$projectId/functions/$functionId/settings/runtime'
  | '/projects/$projectId/functions/$functionId/settings/executions'

type SiteSettingsPath =
  | '/projects/$projectId/sites/$siteId/settings'
  | '/projects/$projectId/sites/$siteId/settings/git'
  | '/projects/$projectId/sites/$siteId/settings/build'
  | '/projects/$projectId/sites/$siteId/settings/runtime'

const FUNCTION_SETTINGS_TO: Record<
  ResourceSettingsNavItem['pathSuffix'],
  FunctionSettingsPath
> = {
  '': '/projects/$projectId/functions/$functionId/settings',
  git: '/projects/$projectId/functions/$functionId/settings/git',
  build: '/projects/$projectId/functions/$functionId/settings/build',
  runtime: '/projects/$projectId/functions/$functionId/settings/runtime',
  executions:
    '/projects/$projectId/functions/$functionId/settings/executions',
}

const SITE_SETTINGS_TO: Record<
  ResourceSettingsNavItem['pathSuffix'],
  SiteSettingsPath
> = {
  '': '/projects/$projectId/sites/$siteId/settings',
  git: '/projects/$projectId/sites/$siteId/settings/git',
  build: '/projects/$projectId/sites/$siteId/settings/build',
  runtime: '/projects/$projectId/sites/$siteId/settings/runtime',
  /** Unused: site settings nav has no executions item */
  executions: '/projects/$projectId/sites/$siteId/settings',
}

function useActiveSettingsSection(pathname: string): string {
  return useMemo(() => {
    const parts = pathname.split('/').filter(Boolean)
    const i = parts.indexOf('settings')
    const next = i >= 0 ? parts[i + 1] : undefined
    if (!next) return 'general'
    if (next === 'danger-zone') return 'general'
    if (['git', 'build', 'runtime', 'executions'].includes(next)) return next
    return 'general'
  }, [pathname])
}

export function ProjectResourceSettingsShell({
  kind,
  navItems,
}: {
  kind: ShellKind
  navItems: ResourceSettingsNavItem[]
}) {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()
  const { projectId, functionId, siteId } = useParams({ strict: false })
  const activeSection = useActiveSettingsSection(location.pathname)

  const functionParams = { projectId: projectId!, functionId: functionId! }
  const siteParams = { projectId: projectId!, siteId: siteId! }
  const paramsForNavigate =
    kind === 'function' ? functionParams : siteParams

  const toForItem = (
    pathSuffix: ResourceSettingsNavItem['pathSuffix'],
  ): FunctionSettingsPath | SiteSettingsPath =>
    kind === 'function'
      ? FUNCTION_SETTINGS_TO[pathSuffix]
      : SITE_SETTINGS_TO[pathSuffix]

  const cardIndex =
    kind === 'function'
      ? FUNCTION_SETTINGS_CARD_INDEX
      : SITE_SETTINGS_CARD_INDEX

  const layoutNavItems = useMemo(
    () =>
      navItems.map((item) => ({
        id: item.id,
        label: t(item.label),
        icon: item.icon,
        keywords: item.keywords,
        to: toForItem(item.pathSuffix),
        params: paramsForNavigate,
      })),
    [navItems, kind, projectId, functionId, siteId, t],
  )

  return (
    <SettingsLayoutShell
      navItems={layoutNavItems}
      activeSectionId={activeSection}
      cardIndex={cardIndex}
      onNavigateToSection={(sectionId) => {
        const item = navItems.find((n) => n.id === sectionId)
        if (!item) return
        navigate({
          to: toForItem(item.pathSuffix),
          params: paramsForNavigate,
        })
      }}
    >
      <Outlet />
    </SettingsLayoutShell>
  )
}
