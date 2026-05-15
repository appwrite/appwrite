import { useMemo, useState } from 'react'
import { Link, Outlet, useLocation, useNavigate, useParams } from '@tanstack/react-router'
import type { LucideIcon } from 'lucide-react'
import { Search, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export type ResourceSettingsNavItem = {
  id: string
  label: string
  /** Path after `.../settings` - use `''` for index */
  pathSuffix: '' | 'git' | 'build' | 'runtime' | 'triggers'
  icon: LucideIcon
  keywords: string[]
}

type ShellKind = 'function' | 'site'

type FunctionSettingsPath =
  | '/projects/$projectId/functions/$functionId/settings'
  | '/projects/$projectId/functions/$functionId/settings/git'
  | '/projects/$projectId/functions/$functionId/settings/build'
  | '/projects/$projectId/functions/$functionId/settings/runtime'
  | '/projects/$projectId/functions/$functionId/settings/triggers'

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
  triggers: '/projects/$projectId/functions/$functionId/settings/triggers',
}

const SITE_SETTINGS_TO: Record<
  ResourceSettingsNavItem['pathSuffix'],
  SiteSettingsPath
> = {
  '': '/projects/$projectId/sites/$siteId/settings',
  git: '/projects/$projectId/sites/$siteId/settings/git',
  build: '/projects/$projectId/sites/$siteId/settings/build',
  runtime: '/projects/$projectId/sites/$siteId/settings/runtime',
  /** Unused: site settings nav has no triggers item */
  triggers: '/projects/$projectId/sites/$siteId/settings',
}

function useActiveSettingsSection(pathname: string): string {
  return useMemo(() => {
    const parts = pathname.split('/').filter(Boolean)
    const i = parts.indexOf('settings')
    const next = i >= 0 ? parts[i + 1] : undefined
    if (!next) return 'general'
    if (next === 'danger-zone') return 'general'
    if (['git', 'build', 'runtime', 'triggers'].includes(next)) return next
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
  const location = useLocation()
  const navigate = useNavigate()
  const { projectId, functionId, siteId } = useParams({ strict: false })
  const [navSearch, setNavSearch] = useState('')
  const activeSection = useActiveSettingsSection(location.pathname)

  const functionParams = { projectId: projectId!, functionId: functionId! }
  const siteParams = { projectId: projectId!, siteId: siteId! }

  const toForItem = (
    pathSuffix: ResourceSettingsNavItem['pathSuffix'],
  ): FunctionSettingsPath | SiteSettingsPath =>
    kind === 'function'
      ? FUNCTION_SETTINGS_TO[pathSuffix]
      : SITE_SETTINGS_TO[pathSuffix]

  const paramsForNavigate =
    kind === 'function' ? functionParams : siteParams

  const filteredNavItems = useMemo(() => {
    const q = navSearch.trim().toLowerCase()
    const matchesQuery = (item: ResourceSettingsNavItem) => {
      const matchLabel = item.label.toLowerCase().includes(q)
      const matchKeyword = item.keywords.some((k) =>
        k.toLowerCase().includes(q),
      )
      const matchId = item.id.toLowerCase().includes(q)
      const pathKey = (item.pathSuffix || '').toLowerCase()
      const matchPath =
        pathKey.length > 0 &&
        (pathKey.includes(q) ||
          pathKey.replace(/-/g, '').includes(q.replace(/-/g, '')))
      return matchLabel || matchKeyword || matchId || matchPath
    }
    if (!q) return navItems
    const filtered = navItems.filter(matchesQuery)
    const active = navItems.find((n) => n.id === activeSection)
    if (active && !filtered.some((n) => n.id === active.id)) {
      return [active, ...filtered]
    }
    return filtered
  }, [navSearch, navItems, activeSection])

  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:gap-8">
      <div className="lg:hidden space-y-2" aria-label="Settings section">
        <div className="relative w-full">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
          <Input
            placeholder="Search settings..."
            value={navSearch}
            onChange={(e) => setNavSearch(e.target.value)}
            className={cn(
              'h-9 w-full rounded-md border border-border bg-accent/50 pl-10 pr-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
              navSearch && 'pr-9',
            )}
          />
          {navSearch && (
            <button
              type="button"
              onClick={() => setNavSearch('')}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground rounded p-0.5"
              aria-label="Clear search"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
        <Select
          value={activeSection}
          onValueChange={(value) => {
            const item = navItems.find((n) => n.id === value)
            if (!item) return
            navigate({
              to: toForItem(item.pathSuffix),
              params: paramsForNavigate,
            })
          }}
        >
          <SelectTrigger size="sm" className="h-9 w-full text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {(filteredNavItems.length > 0 ? filteredNavItems : navItems).map(
              (item) => (
                <SelectItem
                  key={item.id}
                  value={item.id}
                  className="text-[13px]"
                >
                  <span className="flex items-center gap-2">
                    <item.icon className="h-4 w-4 shrink-0" />
                    {item.label}
                  </span>
                </SelectItem>
              ))}
          </SelectContent>
        </Select>
      </div>

      <nav
        className="hidden lg:flex sticky top-4 w-56 shrink-0 flex-col gap-2 self-start"
        aria-label="Settings navigation"
      >
        <div className="relative w-full mb-2">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
          <Input
            placeholder="Search settings..."
            value={navSearch}
            onChange={(e) => setNavSearch(e.target.value)}
            className={cn(
              'h-9 w-full rounded-md border border-border bg-accent/50 pl-10 pr-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
              navSearch && 'pr-9',
            )}
          />
          {navSearch && (
            <button
              type="button"
              onClick={() => setNavSearch('')}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground rounded p-0.5"
              aria-label="Clear search"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
        {filteredNavItems.length === 0 ? (
          <p className="px-3 py-2 text-[12px] text-muted-foreground">
            No matching settings
          </p>
        ) : (
          filteredNavItems.map((item) => {
            const Icon = item.icon
            const to = toForItem(item.pathSuffix)
            return (
              <Link
                key={item.id}
                to={to}
                params={paramsForNavigate}
                className={cn(
                  'flex items-center gap-2 rounded-md px-3 py-2 text-[13px] font-medium transition-colors',
                  activeSection === item.id
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {item.label}
              </Link>
            )
          })
        )}
      </nav>

      <div className="min-w-0 flex-1 space-y-6">
        <Outlet />
      </div>
    </div>
  )
}
