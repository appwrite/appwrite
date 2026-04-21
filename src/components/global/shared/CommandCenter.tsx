import { useState, useEffect, useMemo, useCallback, useRef } from 'react'
import { cn } from '@/lib/utils'
import { useIsMobile } from '@/hooks/use-mobile'
import {
  Building2,
  Clock,
  Database,
  Folder,
  FolderOpen,
  Globe,
  Keyboard,
  Play,
  Terminal,
  Users,
  X,
  Zap,
  ArrowRight,
  type LucideIcon,
} from 'lucide-react'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { VisuallyHidden } from '@radix-ui/react-visually-hidden'
import { Badge } from '@/components/ui/badge'
import {
  useProjectsForTeam,
  useProjectDatabases,
  useProjectUsers,
  useProjectTeams,
  useProjectBuckets,
  useProjectFunctions,
  useProjectSites,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { useNavigate } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  DEFAULT_GROUP_LABELS,
  getCommandsForContext,
  searchCommands,
  type CommandContext,
  type CommandEntry,
  type CommandKind,
  type CommandScope,
} from '@/lib/command-center'
import { FULL_ACCESS } from '@/lib/console-roles'
import type {
  CommandCenterContext,
  CreateResourceType,
} from './CommandCenter.types'

export type { CommandCenterContext, CreateResourceType }

type ResourceScope =
  | 'databases'
  | 'users'
  | 'teams'
  | 'buckets'
  | 'functions'
  | 'sites'
  | 'projects'

interface ResourceSearchSpec {
  scope: ResourceScope
  label: string
  description: string
  icon: LucideIcon
  availableScopes: CommandCenterContext[]
}

const RESOURCE_SEARCH_SPECS: ResourceSearchSpec[] = [
  {
    scope: 'databases',
    label: 'Search databases',
    description: 'Find a database by name or ID',
    icon: Database,
    availableScopes: ['project'],
  },
  {
    scope: 'users',
    label: 'Search users',
    description: 'Find a user by name, email or ID',
    icon: Users,
    availableScopes: ['project'],
  },
  {
    scope: 'teams',
    label: 'Search teams',
    description: 'Find a team by name or ID',
    icon: Building2,
    availableScopes: ['project'],
  },
  {
    scope: 'buckets',
    label: 'Search buckets',
    description: 'Find a storage bucket by name or ID',
    icon: Folder,
    availableScopes: ['project'],
  },
  {
    scope: 'functions',
    label: 'Search functions',
    description: 'Find a function by name or ID',
    icon: Zap,
    availableScopes: ['project'],
  },
  {
    scope: 'sites',
    label: 'Search sites',
    description: 'Find a site by name or ID',
    icon: Globe,
    availableScopes: ['project'],
  },
  {
    scope: 'projects',
    label: 'Search projects',
    description: 'Find a project in this organization',
    icon: FolderOpen,
    availableScopes: ['org'],
  },
]

interface CommandCenterProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Sidebar/legacy callback for top-level project navigation. */
  onNavigate?: (section: string) => void
  /** Navigate to a specific resource (e.g. database, user) - goes to detail page */
  onNavigateToResource?: (section: string, resourceId: string) => void
  /** Navigate and open the create modal/wizard for the given resource type */
  onCreateResource?: (type: CreateResourceType) => void
  context?: CommandCenterContext
  /** Org context: legacy navigation callback. */
  onOrgNavigate?: (tab: string) => void
  /** Callback when "Invite Member" is selected (org context) */
  onInviteMember?: () => void
  projectId?: string | null
  orgId?: string | null
}

function toRegistryScope(ctx: CommandCenterContext): CommandScope {
  if (ctx === 'org') return 'organization'
  if (ctx === 'account') return 'account'
  return 'project'
}

function navigateToHref(navigate: ReturnType<typeof useNavigate>, href: string) {
  // Use TanStack Router's navigate when possible to keep history nice. We pass
  // raw paths; hash-only changes still trigger `hashchange` for our scroll hook.
  navigate({ to: href as never, replace: false }).catch(() => {
    if (typeof window !== 'undefined') window.location.assign(href)
  })
}

export function CommandCenter({
  open,
  onOpenChange,
  onNavigate,
  onNavigateToResource,
  onCreateResource,
  context = 'project',
  onOrgNavigate,
  onInviteMember,
  projectId,
  orgId,
}: CommandCenterProps) {
  const [search, setSearch] = useState('')
  const [pages, setPages] = useState<string[]>([])
  const [searchScope, setSearchScope] = useState<ResourceScope | null>(null)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const displayedResourceCommandsRef = useRef<RuntimeCommand[]>([])
  const prevSearchScopeRef = useRef<typeof searchScope>(null)
  const currentPage = pages[pages.length - 1]
  const isMobile = useIsMobile()
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const isOrgContext = context === 'org'
  const isProjectContext = context === 'project'

  // RBAC: resolve org/team for scopes
  const { project } = useProject(
    isProjectContext ? (projectId ?? undefined) : undefined,
  )
  const scopesOrgId = isOrgContext ? (orgId ?? undefined) : project?.teamId
  const { access: rbacAccess } = useOrganizationScopes(scopesOrgId)
  const access = rbacAccess ?? FULL_ACCESS

  // Build the runtime CommandContext used by registry entries.
  const closeCommandCenter = useCallback(() => onOpenChange(false), [onOpenChange])
  const openShortcutsPage = useCallback(
    () => setPages((p) => [...p, 'shortcuts']),
    [],
  )

  const ctx: CommandContext = useMemo(
    () => ({
      scope: toRegistryScope(context),
      projectId: projectId ?? null,
      orgId: orgId ?? null,
      features,
      access,
      isMobile,
      navigate: (href) => navigateToHref(navigate, href),
      navigateExternal: (href) => {
        if (typeof window !== 'undefined') window.location.assign(href)
      },
      closeCommandCenter,
      openShortcutsPage,
      handlers: {
        onProjectCreate: onCreateResource,
        onOrgInviteMember: onInviteMember,
      },
    }),
    [
      context,
      projectId,
      orgId,
      features,
      access,
      isMobile,
      navigate,
      closeCommandCenter,
      openShortcutsPage,
      onCreateResource,
      onInviteMember,
    ],
  )

  // Load entries from registry, lift them into runtime commands.
  const registryCommands: RuntimeCommand[] = useMemo(
    () => getCommandsForContext(ctx).map((entry) => toRuntimeCommand(entry, ctx)),
    [ctx],
  )

  // Local-state actions that always show in the default list.
  const localActionCommands: RuntimeCommand[] = useMemo(() => {
    const commands: RuntimeCommand[] = []
    if (!isMobile) {
      commands.push({
        id: 'action.shortcuts',
        label: 'Keyboard shortcuts',
        description: 'See all keyboard shortcuts',
        icon: Keyboard,
        kind: 'action',
        shortcut: '?',
        keywords: ['help', 'keys', 'hotkeys', 'shortcuts'],
        select: () => setPages((p) => [...p, 'shortcuts']),
      })
    }
    return commands
  }, [isMobile])

  // Search-only actions: discoverable via the search box but not surfaced
  // in the default landing list (they would feel out-of-context there).
  const searchOnlyActionCommands: RuntimeCommand[] = useMemo(() => {
    const commands: RuntimeCommand[] = []
    if (isProjectContext) {
      commands.push({
        id: 'action.execute-function',
        label: 'Execute function',
        description: 'Pick a function to execute',
        icon: Play,
        kind: 'action',
        keywords: ['run', 'execute', 'invoke', 'function'],
        select: () => setPages((p) => [...p, 'functions']),
      })
      if (features.activity) {
        commands.push({
          id: 'action.view-logs',
          label: 'View activity log',
          description: 'Open project activity log',
          icon: Terminal,
          kind: 'action',
          shortcut: isMobile ? undefined : 'L',
          keywords: ['logs', 'activity', 'audit', 'console'],
          select: () => {
            onOpenChange(false)
            if (projectId) {
              navigateToHref(navigate, `/projects/${projectId}/activity`)
            } else if (onNavigate) onNavigate('activity')
          },
        })
      }
    }
    return commands
  }, [
    isProjectContext,
    isMobile,
    features.activity,
    navigate,
    projectId,
    onOpenChange,
    onNavigate,
  ])

  // Search-resource CTAs (filtered by current scope).
  const resourceSearchCtas: RuntimeCommand[] = useMemo(() => {
    return RESOURCE_SEARCH_SPECS.filter((s) =>
      s.availableScopes.includes(context),
    ).map((spec) => ({
      id: `search.${spec.scope}`,
      label: spec.label,
      description: spec.description,
      icon: spec.icon,
      kind: 'action' as CommandKind,
      group: 'Search',
      keywords: [spec.scope, 'search', 'find'],
      select: () => {
        setSearchScope(spec.scope)
        setSearch('')
      },
      isResourceSearch: true,
    }))
  }, [context])

  // Recent items - placeholder; kept lightweight (project context for now).
  const recentCommands: RuntimeCommand[] = useMemo(() => {
    if (!isProjectContext) return []
    return [
      {
        id: 'recent.project-databases',
        label: 'Production database',
        description: 'Recently opened',
        icon: Clock,
        kind: 'action',
        group: 'Recent',
        select: () => {
          onOpenChange(false)
          if (projectId) navigateToHref(navigate, `/projects/${projectId}/databases`)
        },
      },
    ]
  }, [isProjectContext, navigate, projectId, onOpenChange])

  // ── Dynamic resource fetching (search by user-typed term) ────────────────
  const hasSearch = search.trim().length > 0
  const hasScope = searchScope !== null
  const shouldFetch = hasSearch || hasScope

  const { projects: orgProjects, isLoading: orgProjectsLoading } =
    useProjectsForTeam(
      isOrgContext && orgId && shouldFetch && searchScope === 'projects'
        ? orgId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'projects' ? search : undefined,
    )

  const { databases: projectDatabases, isLoading: databasesLoading } =
    useProjectDatabases(
      isProjectContext && projectId && shouldFetch && searchScope === 'databases'
        ? projectId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'databases' ? search : undefined,
    )

  const { users: projectUsers, isLoading: usersLoading } = useProjectUsers(
    isProjectContext && projectId && shouldFetch && searchScope === 'users'
      ? projectId
      : null,
    0,
    100,
    shouldFetch && searchScope === 'users' ? search : undefined,
  )

  const { teams: projectTeams, isLoading: teamsLoading } = useProjectTeams(
    isProjectContext && projectId && shouldFetch && searchScope === 'teams'
      ? projectId
      : null,
    0,
    100,
    shouldFetch && searchScope === 'teams' ? search : undefined,
  )

  const { buckets: projectBuckets, isLoading: bucketsLoading } =
    useProjectBuckets(
      isProjectContext && projectId && shouldFetch && searchScope === 'buckets'
        ? projectId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'buckets' ? search : undefined,
    )

  const { functions: projectFunctions, isLoading: functionsLoading } =
    useProjectFunctions(
      isProjectContext &&
        projectId &&
        shouldFetch &&
        searchScope === 'functions'
        ? projectId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'functions' ? search : undefined,
    )

  const { sites: projectSites, isLoading: sitesLoading } = useProjectSites(
    isProjectContext && projectId && shouldFetch && searchScope === 'sites'
      ? projectId
      : null,
    0,
    100,
    shouldFetch && searchScope === 'sites' ? search : undefined,
  )

  // Reset state when dialog closes
  useEffect(() => {
    if (!open) {
      setSearch('')
      setPages([])
      setSearchScope(null)
    }
  }, [open])

  const isScopeLoading = useMemo(() => {
    if (!searchScope) return false
    switch (searchScope) {
      case 'databases':
        return databasesLoading
      case 'users':
        return usersLoading
      case 'teams':
        return teamsLoading
      case 'buckets':
        return bucketsLoading
      case 'functions':
        return functionsLoading
      case 'sites':
        return sitesLoading
      case 'projects':
        return orgProjectsLoading
      default:
        return false
    }
  }, [
    searchScope,
    databasesLoading,
    usersLoading,
    teamsLoading,
    bucketsLoading,
    functionsLoading,
    sitesLoading,
    orgProjectsLoading,
  ])

  const hasShownScopeResults = useRef(false)

  // Build dynamic resource commands for the active scope.
  const resolvedResourceCommands: RuntimeCommand[] = useMemo(() => {
    const items: RuntimeCommand[] = []
    if (!searchScope) return items

    if (searchScope === 'databases' && projectDatabases && !databasesLoading) {
      projectDatabases.forEach((db: unknown) => {
        const dbId = (db as { $id: string }).$id
        items.push({
          id: `db-${dbId}`,
          label: (db as { name: string }).name,
          description: `Database · ${dbId}`,
          icon: Database,
          kind: 'action',
          select: () => {
            if (onNavigateToResource) onNavigateToResource('databases', dbId)
            else onNavigate?.('databases')
            onOpenChange(false)
          },
        })
      })
    }
    if (searchScope === 'users' && projectUsers && !usersLoading) {
      projectUsers.forEach((user) => {
        items.push({
          id: `user-${user.$id}`,
          label: user.name || user.email || 'Unknown',
          description: `User · ${user.email || 'No email'}`,
          icon: Users,
          kind: 'action',
          select: () => {
            if (onNavigateToResource) onNavigateToResource('auth/users', user.$id)
            else onNavigate?.('auth')
            onOpenChange(false)
          },
        })
      })
    }
    if (searchScope === 'teams' && projectTeams && !teamsLoading) {
      projectTeams.forEach((team) => {
        items.push({
          id: `team-${team.id}`,
          label: team.name,
          description: `Team · ${team.id}`,
          icon: Building2,
          kind: 'action',
          select: () => {
            if (onNavigateToResource) onNavigateToResource('auth/teams', team.id)
            else onNavigate?.('auth')
            onOpenChange(false)
          },
        })
      })
    }
    if (searchScope === 'buckets' && projectBuckets && !bucketsLoading) {
      projectBuckets.forEach((bucket) => {
        items.push({
          id: `bucket-${bucket.$id}`,
          label: bucket.name,
          description: `Bucket · ${bucket.$id}`,
          icon: Folder,
          kind: 'action',
          select: () => {
            if (onNavigateToResource) onNavigateToResource('storage', bucket.$id)
            else onNavigate?.('storage')
            onOpenChange(false)
          },
        })
      })
    }
    if (searchScope === 'functions' && projectFunctions && !functionsLoading) {
      projectFunctions.forEach((fn) => {
        items.push({
          id: `fn-${fn.$id}`,
          label: fn.name,
          description: `Function · ${fn.runtime || 'unknown'}`,
          icon: Zap,
          kind: 'action',
          select: () => {
            if (onNavigateToResource) onNavigateToResource('functions', fn.$id)
            else onNavigate?.('functions')
            onOpenChange(false)
          },
        })
      })
    }
    if (searchScope === 'sites' && projectSites && !sitesLoading) {
      projectSites.forEach((site: { $id: string; name: string }) => {
        items.push({
          id: `site-${site.$id}`,
          label: site.name,
          description: `Site · ${site.$id}`,
          icon: Globe,
          kind: 'action',
          select: () => {
            if (onNavigateToResource) onNavigateToResource('sites', site.$id)
            else onNavigate?.('sites')
            onOpenChange(false)
          },
        })
      })
    }
    if (searchScope === 'projects' && orgProjects && !orgProjectsLoading) {
      orgProjects.forEach((project) => {
        items.push({
          id: `project-${project.$id}`,
          label: project.name,
          description: features.multiRegion
            ? `Project · ${project.region || 'unknown'}`
            : 'Project',
          icon: FolderOpen,
          kind: 'action',
          select: () => {
            onOpenChange(false)
            navigateToHref(navigate, `/projects/${project.$id}`)
          },
        })
      })
    }
    return items
  }, [
    searchScope,
    projectDatabases,
    databasesLoading,
    projectUsers,
    usersLoading,
    projectTeams,
    teamsLoading,
    projectBuckets,
    bucketsLoading,
    projectFunctions,
    functionsLoading,
    projectSites,
    sitesLoading,
    orgProjects,
    orgProjectsLoading,
    features.multiRegion,
    navigate,
    onNavigate,
    onNavigateToResource,
    onOpenChange,
  ])

  useEffect(() => {
    if (searchScope !== prevSearchScopeRef.current) {
      prevSearchScopeRef.current = searchScope
      displayedResourceCommandsRef.current = []
    }
  }, [searchScope])
  useEffect(() => {
    if (!isScopeLoading && searchScope) {
      displayedResourceCommandsRef.current = resolvedResourceCommands
    }
  }, [isScopeLoading, searchScope, resolvedResourceCommands])
  const resourceCommands: RuntimeCommand[] = isScopeLoading
    ? displayedResourceCommandsRef.current
    : resolvedResourceCommands

  // Bridge legacy callbacks: when a registry navigation entry resolves to
  // a top-level project section (`/projects/{id}` or `/projects/{id}/{section}`
  // with no further path), prefer the parent-provided `onNavigate` so the
  // sequential keyboard shortcuts and the command-center take a single code
  // path. Multi-segment paths (tabs, cards with hashes) fall through to direct
  // navigation so they don't get truncated.
  const wrapWithLegacyNavigate = useCallback(
    (cmd: RuntimeCommand): RuntimeCommand => {
      if (!isProjectContext) return cmd
      if (cmd.kind !== 'navigation') return cmd
      if (!onNavigate) return cmd
      const href = cmd.href
      if (!href || !projectId) return cmd
      const prefix = `/projects/${projectId}`
      if (!href.startsWith(prefix)) return cmd
      const rest = href.slice(prefix.length).replace(/^\//, '')
      // Skip when the path has additional segments or a hash anchor — those
      // need real navigation rather than the section-only callback.
      if (rest.includes('/') || rest.includes('#')) return cmd
      const section = rest === '' ? 'overview' : rest
      return {
        ...cmd,
        select: () => {
          onOpenChange(false)
          onNavigate(section)
        },
      }
    },
    [isProjectContext, projectId, onNavigate, onOpenChange],
  )

  // Org overview is single-page with tab state derived from the URL. Direct
  // route navigation (the registry default) updates the URL and so updates
  // the active tab. We still prefer the parent `onOrgNavigate` callback when
  // the path is a top-level org route it knows about (projects/domains/settings)
  // so its `replace: true` history behavior is preserved.
  const wrapOrgLegacyNavigate = useCallback(
    (cmd: RuntimeCommand): RuntimeCommand => {
      if (!isOrgContext) return cmd
      if (!onOrgNavigate) return cmd
      if (cmd.kind !== 'navigation') return cmd
      const href = cmd.href
      if (!href || !orgId) return cmd
      const prefix = `/organizations/${orgId}`
      if (!href.startsWith(prefix)) return cmd
      const tail = href.slice(prefix.length).replace(/^\//, '').replace(/\/$/, '')
      // Only the top-level org tabs are forwarded to the legacy callback.
      // Settings sub-tabs and other deep links route directly so we don't
      // depend on the host knowing every key.
      const knownTabs = new Set(['', 'projects', 'domains', 'settings'])
      if (!knownTabs.has(tail)) return cmd
      const tab = tail === '' ? 'projects' : tail
      return {
        ...cmd,
        select: () => {
          onOpenChange(false)
          onOrgNavigate(tab)
        },
      }
    },
    [isOrgContext, onOrgNavigate, orgId, onOpenChange],
  )

  const enrichedRegistryCommands = useMemo(
    () => registryCommands.map(wrapOrgLegacyNavigate).map(wrapWithLegacyNavigate),
    [registryCommands, wrapWithLegacyNavigate, wrapOrgLegacyNavigate],
  )

  // Combined pool used for free-text search. Includes everything that the
  // search box should be able to surface, even if it's hidden from the
  // default landing list.
  const allCommands: RuntimeCommand[] = useMemo(
    () => [
      ...enrichedRegistryCommands,
      ...resourceSearchCtas,
      ...localActionCommands,
      ...searchOnlyActionCommands,
    ],
    [
      enrichedRegistryCommands,
      resourceSearchCtas,
      localActionCommands,
      searchOnlyActionCommands,
    ],
  )

  // Group commands shown when no search query is active (default view).
  // Tabs and cards are intentionally excluded here — they would clutter the
  // landing list with deep-link variants of pages already visible under
  // Navigation. They remain reachable via the search box.
  const defaultGroups = useMemo(() => {
    const sections: Array<{ id: string; label: string; commands: RuntimeCommand[] }> = []

    if (recentCommands.length > 0) {
      sections.push({ id: 'recent', label: 'Recent', commands: recentCommands })
    }

    const buckets = new Map<string, RuntimeCommand[]>()
    for (const cmd of enrichedRegistryCommands) {
      if (cmd.kind === 'tab' || cmd.kind === 'card') continue
      const label = cmd.group ?? DEFAULT_GROUP_LABELS[cmd.kind]
      const arr = buckets.get(label) ?? []
      arr.push(cmd)
      buckets.set(label, arr)
    }
    if (resourceSearchCtas.length > 0) {
      buckets.set('Search', [
        ...(buckets.get('Search') ?? []),
        ...resourceSearchCtas,
      ])
    }
    if (localActionCommands.length > 0) {
      buckets.set('Actions', [
        ...(buckets.get('Actions') ?? []),
        ...localActionCommands,
      ])
    }

    // Group order: Navigation → Create → Search → Actions, then alphabetical.
    const HEAD = [DEFAULT_GROUP_LABELS.navigation, DEFAULT_GROUP_LABELS.create]
    const TAIL = ['Search', 'Actions']
    const labels = Array.from(buckets.keys()).sort((a, b) => {
      const ai = HEAD.indexOf(a)
      const bi = HEAD.indexOf(b)
      const at = TAIL.indexOf(a)
      const bt = TAIL.indexOf(b)
      if (ai !== -1 || bi !== -1) {
        if (ai === -1) return 1
        if (bi === -1) return -1
        return ai - bi
      }
      if (at !== -1 || bt !== -1) {
        if (at === -1) return -1
        if (bt === -1) return 1
        return at - bt
      }
      return a.localeCompare(b)
    })
    for (const label of labels) {
      const cmds = buckets.get(label)!
      if (cmds.length === 0) continue
      sections.push({ id: label.toLowerCase(), label, commands: cmds })
    }
    return sections
  }, [
    recentCommands,
    enrichedRegistryCommands,
    resourceSearchCtas,
    localActionCommands,
  ])

  // Memoize the visible groups (drops empty buckets so an empty resource
  // scope doesn't render a stray "Databases" heading underneath the empty
  // state text).
  const filteredGroups = useMemo(() => {
    // When user is in a resource search scope, just show the dynamic results.
    if (searchScope) {
      const labels: Record<ResourceScope, string> = {
        databases: 'Databases',
        users: 'Users',
        teams: 'Teams',
        buckets: 'Buckets',
        functions: 'Functions',
        sites: 'Sites',
        projects: 'Projects',
      }
      return [{ id: 'resources', label: labels[searchScope], commands: resourceCommands }]
    }

    if (!search.trim()) {
      return defaultGroups
    }

    const matched = searchCommandsRuntime(search, allCommands)
    if (matched.length === 0) return []

    // Group matched commands by their group/kind label.
    const buckets = new Map<string, RuntimeCommand[]>()
    for (const cmd of matched) {
      const label = cmd.group ?? DEFAULT_GROUP_LABELS[cmd.kind]
      const arr = buckets.get(label) ?? []
      arr.push(cmd)
      buckets.set(label, arr)
    }
    return Array.from(buckets.entries()).map(([label, commands]) => ({
      id: label.toLowerCase(),
      label,
      commands,
    }))
  }, [search, searchScope, defaultGroups, allCommands, resourceCommands])

  const nonEmptyGroups = useMemo(
    () => filteredGroups.filter((group) => group.commands.length > 0),
    [filteredGroups],
  )

  // Auto-focus the search input whenever the dialog opens or the user
  // navigates between sub-pages. Radix Dialog's initial focus can land on
  // the close button instead of the input, so we focus explicitly.
  useEffect(() => {
    if (!open) return
    const id = window.requestAnimationFrame(() => {
      inputRef.current?.focus()
    })
    return () => window.cancelAnimationFrame(id)
  }, [open, currentPage])

  // Re-focus the input when entering a resource scope and results arrive
  useEffect(() => {
    if (
      searchScope &&
      !isScopeLoading &&
      displayedResourceCommandsRef.current.length > 0 &&
      !hasShownScopeResults.current
    ) {
      hasShownScopeResults.current = true
      requestAnimationFrame(() => {
        inputRef.current?.focus()
      })
    } else if (!searchScope) {
      hasShownScopeResults.current = false
    }
  }, [searchScope, isScopeLoading])

  // Handle keyboard navigation within pages and scope removal.
  // Escape walks back through accumulated state one step at a time:
  //   typed text → scope → sub-page → close dialog.
  // Backspace on an empty input acts as the "back" action.
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Backspace' && !search) {
        if (searchScope) {
          e.preventDefault()
          setSearchScope(null)
          return
        }
        if (pages.length > 0) {
          e.preventDefault()
          setPages(pages.slice(0, -1))
          return
        }
      }

      if (e.key === 'Escape') {
        if (search) {
          e.preventDefault()
          e.stopPropagation()
          setSearch('')
          return
        }
        if (searchScope) {
          e.preventDefault()
          e.stopPropagation()
          setSearchScope(null)
          return
        }
        if (pages.length > 0) {
          e.preventDefault()
          e.stopPropagation()
          setPages(pages.slice(0, -1))
          return
        }
        // Otherwise: let the Dialog handle Escape and close the command center.
      }
    },
    [search, searchScope, pages],
  )

  // ── Shortcuts page ──────────────────────────────────────────────────────
  if (currentPage === 'shortcuts') {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent
          className={cn(
            'overflow-hidden border-border bg-popover p-0 shadow-2xl',
            isMobile
              ? 'h-[100dvh] w-screen max-w-none rounded-none border-0'
              : 'max-w-2xl',
          )}
          showCloseButton={false}
          aria-describedby={undefined}
        >
          <VisuallyHidden>
            <DialogTitle>Keyboard Shortcuts</DialogTitle>
          </VisuallyHidden>
          <Command className="bg-transparent" onKeyDown={handleKeyDown}>
            <div className="flex items-center border-b border-border px-4 py-3">
              <button
                onClick={() => setPages([])}
                className="mr-3 flex h-6 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
              >
                ← Back
              </button>
              <h2 className="flex-1 text-[14px] font-medium text-foreground">
                Keyboard shortcuts
              </h2>
              {isMobile && (
                <button
                  onClick={() => onOpenChange(false)}
                  className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <X className="h-5 w-5" />
                </button>
              )}
            </div>
            <div
              className={cn(
                'overflow-y-auto p-4',
                isMobile ? 'flex-1' : 'max-h-[400px]',
              )}
            >
              <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {buildShortcutGroups(allCommands).map((group) => (
                  <div key={group.label}>
                    <h3 className="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {group.label}
                    </h3>
                    <div className="space-y-2">
                      {group.shortcuts.map((shortcut, i) => (
                        <div
                          key={i}
                          className="flex items-center justify-between"
                        >
                          <span className="text-[13px] text-muted-foreground">
                            {shortcut.description}
                          </span>
                          <div className="flex items-center gap-1">
                            {shortcut.keys.map((key, j) => (
                              <kbd
                                key={j}
                                className="flex h-5 min-w-[20px] items-center justify-center rounded bg-accent px-1.5 text-[10px] font-medium text-muted-foreground"
                              >
                                {key}
                              </kbd>
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </Command>
        </DialogContent>
      </Dialog>
    )
  }

  // ── Functions page (execute function) ───────────────────────────────────
  if (currentPage === 'functions' && context === 'project') {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent
          className={cn(
            'overflow-hidden border-border bg-popover p-0 shadow-2xl',
            isMobile
              ? 'h-[100dvh] w-screen max-w-none rounded-none border-0 flex flex-col'
              : 'max-w-lg',
          )}
          showCloseButton={false}
          aria-describedby={undefined}
        >
          <VisuallyHidden>
            <DialogTitle>Execute function</DialogTitle>
          </VisuallyHidden>
          <Command
            className={cn('bg-transparent', isMobile && 'flex flex-col flex-1')}
            onKeyDown={handleKeyDown}
          >
            <div className="flex items-center border-b border-border [&_[data-slot=command-input-wrapper]]:h-12 [&_[data-slot=command-input-wrapper]]:border-transparent">
              <button
                onClick={() => setPages([])}
                className="ml-3 flex h-6 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
              >
                ← Back
              </button>
              <CommandInput
                placeholder="Select function to execute..."
                value={search}
                onValueChange={setSearch}
                className="border-0 text-foreground placeholder:text-muted-foreground"
              />
              {isMobile && (
                <button
                  onClick={() => onOpenChange(false)}
                  className="mr-3 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <X className="h-5 w-5" />
                </button>
              )}
            </div>
            <CommandList
              className={cn(
                'p-2',
                isMobile ? 'flex-1 max-h-none' : 'max-h-[300px]',
              )}
            >
              <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                No functions found.
              </CommandEmpty>
              <CommandGroup
                heading="Functions"
                className="text-muted-foreground"
              >
                {projectFunctions && projectFunctions.length > 0 ? (
                  projectFunctions.map((fn) => (
                    <CommandItem
                      key={fn.$id}
                      value={fn.name}
                      onSelect={() => {
                        onOpenChange(false)
                      }}
                      className="group flex cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-muted-foreground data-[selected=true]:bg-accent data-[selected=true]:text-foreground"
                    >
                      <div className="flex h-8 w-8 items-center justify-center rounded-md bg-muted group-data-[selected=true]:bg-accent">
                        <Play className="h-4 w-4" />
                      </div>
                      <div className="flex-1">
                        <p className="text-[13px] font-medium">{fn.name}</p>
                        <p className="text-[11px] text-muted-foreground group-data-[selected=true]:text-foreground/80">
                          {fn.runtime || 'unknown'}
                        </p>
                      </div>
                      <span
                        className={cn(
                          'rounded-full px-2 py-0.5 text-[10px] font-medium',
                          'bg-muted text-muted-foreground',
                        )}
                      >
                        Function
                      </span>
                    </CommandItem>
                  ))
                ) : (
                  <div className="px-3 py-2.5 text-[13px] text-muted-foreground">
                    {functionsLoading ? 'Loading...' : 'No functions found'}
                  </div>
                )}
              </CommandGroup>
            </CommandList>
          </Command>
        </DialogContent>
      </Dialog>
    )
  }

  // ── Main command center ────────────────────────────────────────────────
  const placeholder = searchScope
    ? `Search ${searchScope}...`
    : context === 'org'
      ? 'Search projects, settings, members…'
      : context === 'account'
        ? 'Search account, sessions, security…'
        : 'Search anything — pages, tabs, settings, resources…'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          'overflow-hidden border-border bg-popover p-0 shadow-2xl',
          isMobile
            ? 'h-[100dvh] w-screen max-w-none rounded-none border-0 flex flex-col'
            : 'max-w-lg',
        )}
        showCloseButton={false}
        aria-describedby={undefined}
      >
        <VisuallyHidden>
          <DialogTitle>Command center</DialogTitle>
        </VisuallyHidden>
        <Command
          className={cn('bg-transparent', isMobile && 'flex flex-col flex-1')}
          onKeyDown={handleKeyDown}
          shouldFilter={false}
        >
          <div className="flex items-center gap-2 border-b border-border px-3 [&_[data-slot=command-input-wrapper]]:h-14 [&_[data-slot=command-input-wrapper]]:border-transparent [&_[data-slot=command-input-wrapper]]:flex-1">
            {searchScope && (
              <Badge
                variant="secondary"
                className="shrink-0 flex items-center gap-1.5 h-6 px-2 text-[11px] font-medium"
              >
                <span className="capitalize">{searchScope}</span>
                <button
                  onClick={(e) => {
                    e.stopPropagation()
                    setSearchScope(null)
                    setSearch('')
                  }}
                  className="ml-0.5 rounded-sm hover:bg-accent/80 p-0.5 -mr-0.5"
                  aria-label="Remove scope"
                >
                  <X className="h-3 w-3" />
                </button>
              </Badge>
            )}
            <CommandInput
              ref={inputRef}
              placeholder={placeholder}
              value={search}
              onValueChange={setSearch}
              autoFocus
              className="h-14 border-0 text-[14px] text-foreground placeholder:text-muted-foreground"
            />
            {isMobile && (
              <button
                onClick={() => onOpenChange(false)}
                className="shrink-0 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
              >
                <X className="h-5 w-5" />
              </button>
            )}
          </div>
          <CommandList
            className={cn(
              'p-2',
              isMobile ? 'flex-1 min-h-0' : 'h-[min(600px,70dvh)]',
            )}
          >
            {/* Custom empty state: only show "no results" when the user has
                actually typed something or chosen a resource scope. Avoids
                the flash of "No results" before the default list mounts. */}
            {nonEmptyGroups.length === 0 && (search.trim() || searchScope) && (
              <div className="py-6 text-center text-[13px] text-muted-foreground">
                {searchScope && isScopeLoading
                  ? `Searching ${searchScope}…`
                  : 'No results found.'}
              </div>
            )}

            {nonEmptyGroups.map((group, groupIndex) => (
              <div key={group.id}>
                {groupIndex > 0 && (
                  <CommandSeparator className="my-2 bg-border" />
                )}
                <CommandGroup
                  heading={group.label}
                  className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-wider [&_[cmdk-group-heading]]:text-muted-foreground"
                >
                  {group.commands.map((cmd) => {
                    const Icon = cmd.icon
                    return (
                      <CommandItem
                        key={cmd.id}
                        value={`${cmd.id}-${cmd.label}`}
                        onSelect={cmd.select}
                        disabled={cmd.disabled}
                        className="group flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-muted-foreground data-[selected=true]:bg-accent data-[selected=true]:text-foreground"
                      >
                        <div className="flex h-7 w-7 items-center justify-center rounded-md bg-muted group-data-[selected=true]:bg-accent">
                          {Icon && <Icon className="h-3.5 w-3.5" />}
                        </div>
                        <div className="flex-1 overflow-hidden">
                          <p className="truncate text-[13px] font-medium">
                            {cmd.label}
                          </p>
                          {cmd.description && (
                            <p className="truncate text-[11px] text-muted-foreground group-data-[selected=true]:text-foreground/80">
                              {cmd.description}
                            </p>
                          )}
                        </div>
                        {cmd.shortcut && !isMobile && (
                          <div className="flex items-center gap-1">
                            {cmd.shortcut.split(' ').map((key, i) => (
                              <kbd
                                key={i}
                                className="flex h-5 min-w-[20px] items-center justify-center rounded bg-accent px-1.5 text-[10px] font-medium text-muted-foreground"
                              >
                                {key}
                              </kbd>
                            ))}
                          </div>
                        )}
                        {cmd.isResourceSearch && (
                          <ArrowRight className="h-3.5 w-3.5 text-muted-foreground/50" />
                        )}
                      </CommandItem>
                    )
                  })}
                </CommandGroup>
              </div>
            ))}
          </CommandList>

          {!isMobile && (
            <div className="flex items-center justify-between border-t border-border px-3 py-2">
              <div className="flex items-center gap-3 text-[11px] text-muted-foreground/60">
                <span className="flex items-center gap-1">
                  <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">
                    ↑↓
                  </kbd>
                  navigate
                </span>
                <span className="flex items-center gap-1">
                  <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">
                    ↵
                  </kbd>
                  select
                </span>
                <span className="flex items-center gap-1">
                  <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">
                    esc
                  </kbd>
                  close
                </span>
              </div>
              <button
                onClick={() => setPages([...pages, 'shortcuts'])}
                className="flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
              >
                <Keyboard className="h-3 w-3" />
                <span>All shortcuts</span>
              </button>
            </div>
          )}
        </Command>
      </DialogContent>
    </Dialog>
  )
}

// ─── Internal types & helpers ────────────────────────────────────────────

interface RuntimeCommand {
  id: string
  label: string
  description?: string
  icon?: LucideIcon
  shortcut?: string
  kind: CommandKind
  group?: string
  keywords?: string[]
  select: () => void
  disabled?: boolean
  /** Used for the "Search X" CTAs to render the right arrow chevron. */
  isResourceSearch?: boolean
  /** Internal: navigation target (used by legacy callback bridges). */
  href?: string
}

function toRuntimeCommand(entry: CommandEntry, ctx: CommandContext): RuntimeCommand {
  const disabled = entry.disabled?.(ctx) ?? false
  const reason = disabled ? entry.disabledReason?.(ctx) : undefined
  const href = entry.to?.(ctx) ?? null

  const select = () => {
    if (disabled) return
    if (entry.perform) {
      entry.perform(ctx)
      return
    }
    if (href) {
      ctx.closeCommandCenter()
      ctx.navigate(href)
    }
  }

  return {
    id: entry.id,
    label: entry.label,
    description: reason ?? entry.description,
    icon: entry.icon,
    shortcut: entry.shortcut,
    kind: entry.kind,
    group: entry.group,
    keywords: entry.keywords,
    disabled,
    select,
    href: href ?? undefined,
  }
}

/**
 * Smart search adapter: scores each runtime command using the same scoring
 * logic as the registry search but operating on the merged runtime list
 * (which also includes Search CTAs and local actions).
 */
function searchCommandsRuntime(
  query: string,
  commands: RuntimeCommand[],
): RuntimeCommand[] {
  const adapted: CommandEntry[] = commands.map((cmd) => ({
    id: cmd.id,
    label: cmd.label,
    description: cmd.description,
    keywords: cmd.keywords,
    kind: cmd.kind,
    group: cmd.group,
    scopes: ['project', 'organization', 'account'],
  }))
  const ranked = searchCommands(query, adapted)
  const byId = new Map(commands.map((c) => [c.id, c]))
  return ranked
    .map((r) => byId.get(r.entry.id))
    .filter((x): x is RuntimeCommand => Boolean(x))
}

function buildShortcutGroups(commands: RuntimeCommand[]) {
  // Surface only commands with shortcuts; group by their group/kind label.
  const buckets = new Map<string, Array<{ keys: string[]; description: string }>>()
  for (const cmd of commands) {
    if (!cmd.shortcut) continue
    const label = cmd.group ?? DEFAULT_GROUP_LABELS[cmd.kind]
    const arr = buckets.get(label) ?? []
    arr.push({ keys: cmd.shortcut.split(' '), description: cmd.label })
    buckets.set(label, arr)
  }
  // Always include global shortcuts.
  buckets.set('Global', [
    { keys: ['⌘', 'K'], description: 'Open command center' },
    { keys: ['?'], description: 'Show keyboard shortcuts' },
    { keys: ['Esc'], description: 'Close / go back' },
    { keys: ['/'], description: 'Focus search' },
  ])
  return Array.from(buckets.entries()).map(([label, shortcuts]) => ({
    label,
    shortcuts,
  }))
}
