import { useState, useEffect, useLayoutEffect, useMemo, useCallback, useRef, type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useIsMobile } from '@/hooks/use-mobile'
import {
  useKeyboardShortcut,
  usePlatform,
} from '@/hooks/use-keyboard-shortcuts'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import { OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import {
  usePostgresSqlEditorActions,
  registerPostgresSqlJumpToTabPicker,
} from '@/lib/postgres-sql-editor-actions'
import {
  KeyboardShortcutsView,
  ShortcutKeyBadges,
} from '@/components/global/shared/KeyboardShortcutsView'
import { CommandCenterListFooter } from '@/components/global/shared/CommandCenterListFooter'
import {
  Bell,
  Building2,
  Database,
  Folder,
  FolderOpen,
  Globe,
  Keyboard,
  Megaphone,
  PanelTop,
  Play,
  Send,
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
  useProjectConsoleDatabases,
  useProjectUsers,
  useProjectTeams,
  useProjectBuckets,
  useProjectFunctions,
  useProjectSites,
  useProjectMessages,
  useProjectTopics,
  useProjectProviders,
  useProject,
  useOrganizationScopes,
  formatProjectNameForDisplay,
} from '@/lib/react-query/hooks'
import { useNavigate, useLocation } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  DEFAULT_GROUP_LABELS,
  getCommandsForContext,
  PROJECT_RESOURCE_KIND_LABELS,
  RECENT_RESOURCES_MAX_SHOWN,
  getRecentResourceDatabaseIconHints,
  getRecentResourceSiteFramework,
  getRecentResourceBreadcrumbs,
  parseRecentResourceRef,
  searchCommands,
  searchCommandsWithScores,
  getMessageSearchLabel,
  type CommandContext,
  type CommandEntry,
  type CommandKind,
  type CommandScope,
  type ProjectResourceHit,
  type ProjectResourceKind,
} from '@/lib/command-center'
import { canSeeProjectNavItem } from '@/lib/console-access-checks'
import { FULL_ACCESS } from '@/lib/console-roles'
import { useCommandCenterResourceSearch } from '@/hooks/use-command-center-resource-search'
import { DocsSearchView } from '@/components/pages/docs/DocsSearchView'
import { CommandCenterFeedbackView } from '@/components/global/shared/CommandCenterFeedbackView'
import { CommandCenterSupportView } from '@/components/global/shared/CommandCenterSupportView'
import { useDocsPreview } from '@/components/global/providers/DocsPreviewContext'
import { useRecentResourcesSafe } from '@/components/global/providers/RecentResourcesProvider'
import { DatabaseTypeIcon } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeIcon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { isConsoleDocsPreviewPath } from '@/lib/docs/docs-preview-context'
import { getDocsPageUrlFromSlug } from '@/lib/marketing/urls'
import { openInNewWindow } from '@/lib/utils/context-menu'
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
  | 'messages'
  | 'topics'
  | 'providers'
  | 'projects'

const RESOURCE_KIND_ICONS: Record<ProjectResourceKind, LucideIcon> = {
  database: Database,
  user: Users,
  team: Building2,
  bucket: Folder,
  function: Zap,
  site: Globe,
  message: Send,
  topic: Megaphone,
  provider: Bell,
}

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
    scope: 'messages',
    label: 'Search messages',
    description: 'Find a message by content or ID',
    icon: Send,
    availableScopes: ['project'],
  },
  {
    scope: 'topics',
    label: 'Search topics',
    description: 'Find a topic by name or ID',
    icon: Megaphone,
    availableScopes: ['project'],
  },
  {
    scope: 'providers',
    label: 'Search providers',
    description: 'Find a provider by name or ID',
    icon: Bell,
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

const RESOURCE_SEARCH_PLACEHOLDERS: Record<ResourceScope, string> = {
  databases: 'Search databases...',
  users: 'Search users...',
  teams: 'Search teams...',
  buckets: 'Search buckets...',
  functions: 'Search functions...',
  sites: 'Search sites...',
  messages: 'Search messages...',
  topics: 'Search topics...',
  providers: 'Search providers...',
  projects: 'Search projects...',
}

function commandCenterDialogClass(isMobile: boolean, page?: string) {
  return cn(
    'overflow-hidden border-border bg-popover p-0 shadow-2xl',
    isMobile
      ? 'h-[100dvh] w-screen max-w-none rounded-none border-0 flex flex-col'
      : page === 'shortcuts'
        ? 'flex w-full flex-col sm:max-w-5xl h-[85dvh] max-h-[85dvh]'
        : 'w-full sm:max-w-2xl',
  )
}

function commandCenterListHeightClass(isMobile: boolean) {
  return isMobile ? 'max-h-none flex-1 min-h-0' : 'max-h-[min(420px,58dvh)]'
}

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
  /** Org context: open create project dialog */
  onOrgCreateProject?: () => void
  /** Project context: toggle the built-in CLI terminal panel */
  onToggleTerminal?: () => void
  /** Project context: open Connect dialog on the MCP tab */
  onOpenConnectMcp?: () => void
  projectId?: string | null
  orgId?: string | null
  /** When set while opening, navigates to this command center sub-page (e.g. shortcuts). */
  initialSubPage?: string | null
  onInitialSubPageConsumed?: () => void
}

function toRegistryScope(ctx: CommandCenterContext): CommandScope {
  if (ctx === 'org') return 'organization'
  if (ctx === 'account') return 'account'
  if (ctx === 'docs') return 'docs'
  return 'project'
}

function navigateToHref(
  navigate: ReturnType<typeof useNavigate>,
  href: string,
) {
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
  onOrgCreateProject,
  onToggleTerminal,
  onOpenConnectMcp,
  projectId,
  orgId,
  initialSubPage,
  onInitialSubPageConsumed,
}: CommandCenterProps) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [pages, setPages] = useState<string[]>([])
  const [searchScope, setSearchScope] = useState<ResourceScope | null>(null)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const docsInputRef = useRef<HTMLInputElement | null>(null)
  const focusReturnRef = useRef<HTMLElement | null>(null)
  const wasOpenRef = useRef(false)
  const displayedResourceCommandsRef = useRef<RuntimeCommand[]>([])
  const prevSearchScopeRef = useRef<typeof searchScope>(null)
  const currentPage = pages[pages.length - 1]
  const isMobile = useIsMobile()
  const { isMac } = usePlatform()
  const navigate = useNavigate()
  const location = useLocation()
  const recentResources = useRecentResourcesSafe()
  const { features } = useConsoleProfile()
  const { setTheme } = useTheme()
  const isOrgContext = context === 'org'
  const isProjectContext = context === 'project'
  const isConsoleDocsPreviewContext = isConsoleDocsPreviewPath(location.pathname)

  // RBAC: resolve org/team for scopes
  const { project } = useProject(
    isProjectContext ? (projectId ?? undefined) : undefined,
  )
  const scopesOrgId = isOrgContext ? (orgId ?? undefined) : project?.teamId
  const { access: rbacAccess } = useOrganizationScopes(scopesOrgId)
  const access = rbacAccess ?? FULL_ACCESS
  const postgresSqlEditorActions = usePostgresSqlEditorActions()

  const openSqlTabPickerPage = useCallback(() => {
    setPages(['sql-tabs'])
    setSearch('')
    onOpenChange(true)
  }, [onOpenChange])

  useEffect(() => {
    registerPostgresSqlJumpToTabPicker(openSqlTabPickerPage)
    return () => registerPostgresSqlJumpToTabPicker(null)
  }, [openSqlTabPickerPage])

  // Build the runtime CommandContext used by registry entries.
  const closeCommandCenter = useCallback(
    () => onOpenChange(false),
    [onOpenChange],
  )
  const openShortcutsPage = useCallback(
    () => setPages((p) => [...p, 'shortcuts']),
    [],
  )
  const openDocsSearchPage = useCallback(
    () => setPages((p) => [...p, 'docs']),
    [],
  )
  const openFeedbackPage = useCallback(
    () => setPages((p) => [...p, 'feedback']),
    [],
  )
  const openSupportPage = useCallback(
    () => setPages((p) => [...p, 'support']),
    [],
  )

  const { openDocsPreview } = useDocsPreview()

  const handleDocsSelect = useCallback(
    (slug: string) => {
      onOpenChange(false)

      if (!features.marketing) {
        openInNewWindow(getDocsPageUrlFromSlug(slug, false))
        return
      }

      if (isConsoleDocsPreviewContext) {
        openDocsPreview(slug)
        return
      }

      if (!slug) {
        navigate({ to: '/docs/' })
        return
      }
      navigate({ to: '/docs/$', params: { _splat: slug } })
    },
    [
      navigate,
      onOpenChange,
      isConsoleDocsPreviewContext,
      openDocsPreview,
      features.marketing,
    ],
  )

  const ctx: CommandContext = useMemo(
    () => ({
      scope: toRegistryScope(context),
      projectId: projectId ?? null,
      orgId: orgId ?? project?.teamId ?? null,
      pathname: location.pathname,
      features,
      access,
      isMobile,
      navigate: (href) => navigateToHref(navigate, href),
      navigateExternal: (href) => {
        if (typeof window !== 'undefined') window.location.assign(href)
      },
      closeCommandCenter,
      openShortcutsPage,
      openDocsSearchPage,
      openFeedbackPage,
      openSupportPage,
      handlers: {
        onProjectCreate: onCreateResource,
        onOrgInviteMember: onInviteMember,
        onOrgCreateProject,
        onToggleTerminal,
        onSetTheme: setTheme,
        onOpenConnectMcp,
      },
    }),
    [
      context,
      projectId,
      orgId,
      project?.teamId,
      location.pathname,
      features,
      access,
      isMobile,
      navigate,
      closeCommandCenter,
      openShortcutsPage,
      openDocsSearchPage,
      openFeedbackPage,
      openSupportPage,
      onCreateResource,
      onInviteMember,
      onOrgCreateProject,
      onToggleTerminal,
      onOpenConnectMcp,
      setTheme,
    ],
  )

  // When the command center is already open, Cmd/Ctrl+K and / refocus search.
  const focusSearchInput = useCallback(() => {
    if (
      currentPage === 'shortcuts' ||
      currentPage === 'functions' ||
      currentPage === 'sql-tabs' ||
      currentPage === 'feedback' ||
      currentPage === 'support'
    ) {
      return
    }
    if (currentPage === 'docs') {
      docsInputRef.current?.focus()
      return
    }
    inputRef.current?.focus()
  }, [currentPage])

  const refocusSearchShortcut = {
    ...OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS,
    enabled: open,
  }

  useKeyboardShortcut('meta+k', focusSearchInput, refocusSearchShortcut)
  useKeyboardShortcut('control+k', focusSearchInput, refocusSearchShortcut)
  useKeyboardShortcut(
    '/',
    (e) => {
      e.preventDefault()
      focusSearchInput()
    },
    {
      enabled:
        open &&
        currentPage !== 'shortcuts' &&
        currentPage !== 'docs' &&
        currentPage !== 'sql-tabs' &&
        currentPage !== 'feedback' &&
        currentPage !== 'support',
    },
  )

  // Load entries from registry, lift them into runtime commands.
  const registryCommands: RuntimeCommand[] = useMemo(
    () =>
      getCommandsForContext(ctx).map((entry) => toRuntimeCommand(entry, ctx)),
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

  const recentCommands: RuntimeCommand[] = useMemo(() => {
    if (!isProjectContext || !projectId) return []

    const currentRef = parseRecentResourceRef(location.pathname)
    const items =
      recentResources?.getRecentResources({
        projectId,
        limit: RECENT_RESOURCES_MAX_SHOWN,
        skipNewestWhenMatches: currentRef
          ? {
              projectId: currentRef.projectId,
              kind: currentRef.kind,
              resourceId: currentRef.resourceId,
            }
          : null,
      }) ?? []

    return items.map((entry) => {
      const databaseIconHints =
        entry.kind === 'database'
          ? getRecentResourceDatabaseIconHints(entry)
          : null
      const siteFramework =
        entry.kind === 'site'
          ? getRecentResourceSiteFramework(entry)
          : undefined

      return {
        id: `recent.${entry.key}`,
        label: entry.name,
        breadcrumbs: getRecentResourceBreadcrumbs(entry),
        icon: RESOURCE_KIND_ICONS[entry.kind],
        iconElement: siteFramework ? (
          <FrameworkIcon
            framework={siteFramework}
            size="sm"
            className="h-3.5 w-3.5"
          />
        ) : databaseIconHints &&
          (databaseIconHints.apiType || databaseIconHints.engine) ? (
          <DatabaseTypeIcon
            apiType={databaseIconHints.apiType}
            engine={databaseIconHints.engine}
            className="h-3.5 w-3.5"
          />
        ) : undefined,
        kind: 'action' as CommandKind,
        group: 'Recent',
        // Prefer the stored resource path so postgres / product-kind URLs stay correct.
        select: () => {
          onOpenChange(false)
          navigateToHref(navigate, entry.href)
        },
      }
    })
  }, [
    isProjectContext,
    projectId,
    recentResources,
    navigate,
    onOpenChange,
    location.pathname,
  ])

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
    useProjectConsoleDatabases(
      isProjectContext &&
        projectId &&
        shouldFetch &&
        searchScope === 'databases'
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

  const { messages: projectMessages, isLoading: messagesLoading } =
    useProjectMessages(
      isProjectContext && projectId && shouldFetch && searchScope === 'messages'
        ? projectId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'messages' ? search : undefined,
    )

  const { topics: projectTopics, isLoading: topicsLoading } = useProjectTopics(
    isProjectContext && projectId && shouldFetch && searchScope === 'topics'
      ? projectId
      : null,
    0,
    100,
    shouldFetch && searchScope === 'topics' ? search : undefined,
  )

  const { providers: projectProviders, isLoading: providersLoading } =
    useProjectProviders(
      isProjectContext &&
        projectId &&
        shouldFetch &&
        searchScope === 'providers'
        ? projectId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'providers' ? search : undefined,
    )

  const searchableResourceKinds = useMemo(() => {
    const kinds = new Set<ProjectResourceKind>()
    if (!isProjectContext) return kinds

    if (canSeeProjectNavItem(access, features, 'databases'))
      kinds.add('database')
    if (canSeeProjectNavItem(access, features, 'storage')) kinds.add('bucket')
    if (canSeeProjectNavItem(access, features, 'functions'))
      kinds.add('function')
    if (canSeeProjectNavItem(access, features, 'sites')) kinds.add('site')
    if (canSeeProjectNavItem(access, features, 'messaging')) {
      kinds.add('message')
      kinds.add('topic')
      kinds.add('provider')
    }
    kinds.add('user')
    kinds.add('team')
    return kinds
  }, [isProjectContext, access, features])

  const shouldRunUnifiedResourceSearch =
    isProjectContext &&
    !!projectId &&
    hasSearch &&
    !searchScope &&
    searchableResourceKinds.size > 0

  const {
    hits: unifiedResourceHits,
    isLoading: unifiedResourceLoading,
    isFetching: unifiedResourceFetching,
  } = useCommandCenterResourceSearch({
    projectId,
    query: search,
    enabled: shouldRunUnifiedResourceSearch,
    kinds: searchableResourceKinds,
  })

  const displayedUnifiedResourceHitsRef = useRef<ProjectResourceHit[]>([])
  useEffect(() => {
    if (!unifiedResourceFetching && shouldRunUnifiedResourceSearch) {
      displayedUnifiedResourceHitsRef.current = unifiedResourceHits
    }
  }, [
    unifiedResourceFetching,
    shouldRunUnifiedResourceSearch,
    unifiedResourceHits,
  ])
  const stableUnifiedResourceHits =
    unifiedResourceFetching &&
    displayedUnifiedResourceHitsRef.current.length > 0
      ? displayedUnifiedResourceHitsRef.current
      : unifiedResourceHits

  // Reset state when dialog closes
  useEffect(() => {
    if (!open) {
      setSearch('')
      setPages([])
      setSearchScope(null)
    }
  }, [open])

  useEffect(() => {
    if (!open || !initialSubPage) return
    setPages([initialSubPage])
    onInitialSubPageConsumed?.()
  }, [open, initialSubPage, onInitialSubPageConsumed])

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
      case 'messages':
        return messagesLoading
      case 'topics':
        return topicsLoading
      case 'providers':
        return providersLoading
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
    messagesLoading,
    topicsLoading,
    providersLoading,
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
            if (onNavigateToResource)
              onNavigateToResource('auth/users', user.$id)
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
            if (onNavigateToResource)
              onNavigateToResource('auth/teams', team.id)
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
            if (onNavigateToResource)
              onNavigateToResource('storage', bucket.$id)
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
        items.push(
          projectResourceHitToRuntimeCommand(
            {
              id: `site-${site.$id}`,
              kind: 'site',
              label: site.name,
              description: site.$id,
              section: 'sites',
              resourceId: site.$id,
              score: 0,
            },
            {
              onNavigateToResource,
              onNavigate,
              onOpenChange,
            },
          ),
        )
      })
    }
    if (searchScope === 'messages' && projectMessages && !messagesLoading) {
      projectMessages.forEach((message) => {
        items.push(
          projectResourceHitToRuntimeCommand(
            {
              id: `message-${message.$id}`,
              kind: 'message',
              label: getMessageSearchLabel(message),
              description: `${message.providerType} · ${message.$id}`,
              section: 'messaging/messages',
              resourceId: message.$id,
              score: 0,
            },
            {
              onNavigateToResource,
              onNavigate,
              onOpenChange,
            },
          ),
        )
      })
    }
    if (searchScope === 'topics' && projectTopics && !topicsLoading) {
      projectTopics.forEach((topic) => {
        items.push(
          projectResourceHitToRuntimeCommand(
            {
              id: `topic-${topic.$id}`,
              kind: 'topic',
              label: topic.name,
              description: topic.$id,
              section: 'messaging/topics',
              resourceId: topic.$id,
              score: 0,
            },
            {
              onNavigateToResource,
              onNavigate,
              onOpenChange,
            },
          ),
        )
      })
    }
    if (searchScope === 'providers' && projectProviders && !providersLoading) {
      projectProviders.forEach((provider) => {
        items.push(
          projectResourceHitToRuntimeCommand(
            {
              id: `provider-${provider.$id}`,
              kind: 'provider',
              label: provider.name,
              description: `${provider.type} · ${provider.$id}`,
              section: 'messaging/providers',
              resourceId: provider.$id,
              score: 0,
            },
            {
              onNavigateToResource,
              onNavigate,
              onOpenChange,
            },
          ),
        )
      })
    }
    if (searchScope === 'projects' && orgProjects && !orgProjectsLoading) {
      orgProjects.forEach((project) => {
        items.push({
          id: `project-${project.$id}`,
          label: formatProjectNameForDisplay(project.name),
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
    projectMessages,
    messagesLoading,
    projectTopics,
    topicsLoading,
    projectProviders,
    providersLoading,
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
      // Skip when the path has additional segments or a hash anchor - those
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
      const tail = href
        .slice(prefix.length)
        .replace(/^\//, '')
        .replace(/\/$/, '')
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
    () =>
      registryCommands.map(wrapOrgLegacyNavigate).map(wrapWithLegacyNavigate),
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
  // Tabs and cards are intentionally excluded here - they would clutter the
  // landing list with deep-link variants of pages already visible under
  // Navigation. They remain reachable via the search box.
  const defaultGroups = useMemo(() => {
    const sections: Array<{
      id: string
      label: string
      commands: RuntimeCommand[]
    }> = []

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
  const unifiedSearchCommands = useMemo(() => {
    if (!shouldRunUnifiedResourceSearch || !search.trim()) return []

    const scoredCommands = searchCommandsWithScores(
      search,
      allCommands.map((cmd) => ({
        id: cmd.id,
        label: cmd.label,
        description: cmd.description,
        keywords: cmd.keywords,
        kind: cmd.kind,
        group: cmd.group,
      })),
    )
    const commandById = new Map(allCommands.map((cmd) => [cmd.id, cmd]))

    const merged = [
      ...scoredCommands.map(({ entry, score }) => ({
        command: commandById.get(entry.id)!,
        score,
      })),
      ...stableUnifiedResourceHits.map((hit) => ({
        command: projectResourceHitToRuntimeCommand(hit, {
          onNavigateToResource,
          onNavigate,
          onOpenChange,
        }),
        score: hit.score,
      })),
    ]
      .filter((item): item is { command: RuntimeCommand; score: number } =>
        Boolean(item.command),
      )
      .sort((a, b) => {
        if (b.score !== a.score) return b.score - a.score
        return a.command.label.localeCompare(b.command.label)
      })

    return merged.map((item) => item.command)
  }, [
    shouldRunUnifiedResourceSearch,
    search,
    allCommands,
    stableUnifiedResourceHits,
    onNavigateToResource,
    onNavigate,
    onOpenChange,
  ])

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
        messages: 'Messages',
        topics: 'Topics',
        providers: 'Providers',
        projects: 'Projects',
      }
      return [
        {
          id: 'resources',
          label: labels[searchScope],
          commands: resourceCommands,
        },
      ]
    }

    if (!search.trim()) {
      return defaultGroups
    }

    if (shouldRunUnifiedResourceSearch) {
      if (unifiedSearchCommands.length === 0) return []
      return [
        {
          id: 'results',
          label: 'Results',
          commands: unifiedSearchCommands,
        },
      ]
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
  }, [
    search,
    searchScope,
    defaultGroups,
    allCommands,
    resourceCommands,
    shouldRunUnifiedResourceSearch,
    unifiedSearchCommands,
  ])

  const nonEmptyGroups = useMemo(
    () => filteredGroups.filter((group) => group.commands.length > 0),
    [filteredGroups],
  )

  // Capture focus before Radix moves it into the dialog; restore on close (e.g. Escape).
  useLayoutEffect(() => {
    if (open && !wasOpenRef.current) {
      const el = document.activeElement
      focusReturnRef.current = el instanceof HTMLElement ? el : null
    }
    wasOpenRef.current = open
  }, [open])

  const restoreFocusOnClose = useCallback(() => {
    const el = focusReturnRef.current
    focusReturnRef.current = null
    if (!el?.isConnected) return

    requestAnimationFrame(() => {
      if (!el.isConnected) return
      el.focus({ preventScroll: true })
    })
  }, [])

  // Auto-focus the search input whenever the dialog opens or the user
  // navigates between sub-pages. Radix Dialog's initial focus can land on
  // the close button instead of the input, so we focus explicitly.
  useEffect(() => {
    if (!open) return
    const id = window.requestAnimationFrame(() => {
      if (currentPage === 'docs') {
        docsInputRef.current?.focus()
        return
      }
      if (
        currentPage === 'shortcuts' ||
        currentPage === 'functions' ||
        currentPage === 'sql-tabs' ||
        currentPage === 'feedback' ||
        currentPage === 'support'
      ) {
        return
      }
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
  const handleEscape = useCallback(
    (e: { preventDefault: () => void; stopPropagation?: () => void }) => {
      if (search) {
        e.preventDefault()
        e.stopPropagation?.()
        setSearch('')
        return true
      }
      if (searchScope) {
        e.preventDefault()
        e.stopPropagation?.()
        setSearchScope(null)
        return true
      }
      if (pages.length > 0) {
        e.preventDefault()
        e.stopPropagation?.()
        setPages(pages.slice(0, -1))
        return true
      }
      return false
    },
    [search, searchScope, pages],
  )

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
        handleEscape(e)
      }
    },
    [search, searchScope, pages, handleEscape],
  )

  const sqlTabPickerItems = useMemo(() => {
    const tabs = postgresSqlEditorActions?.tabs ?? []
    const query = search.trim().toLowerCase()
    if (!query) return tabs
    return tabs.filter((tab) => tab.title.toLowerCase().includes(query))
  }, [postgresSqlEditorActions?.tabs, search])

  const dialogTitle = t(
    currentPage === 'shortcuts'
      ? 'Keyboard shortcuts'
      : currentPage === 'docs'
        ? 'Search documentation...'
        : currentPage === 'feedback'
          ? 'Send feedback'
          : currentPage === 'support'
            ? 'Support'
            : currentPage === 'functions' && context === 'project'
              ? 'Execute function'
              : currentPage === 'sql-tabs' && context === 'project'
                ? 'Go to query tab'
                : 'Command center',
  )

  const placeholder = t(
    searchScope
      ? RESOURCE_SEARCH_PLACEHOLDERS[searchScope]
      : currentPage === 'sql-tabs'
        ? 'Search query tabs...'
        : context === 'docs'
          ? 'Search commands and documentation pages...'
          : context === 'org'
            ? 'Search projects, settings, members...'
            : context === 'account'
              ? 'Search account, sessions, security...'
              : 'Search anything - pages, tabs, settings, resources...',
  )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={commandCenterDialogClass(isMobile, currentPage)}
        showCloseButton={false}
        aria-describedby={undefined}
        onEscapeKeyDown={(e) => {
          if (handleEscape(e)) return
        }}
        onCloseAutoFocus={(e) => {
          e.preventDefault()
          restoreFocusOnClose()
        }}
      >
        <VisuallyHidden>
          <DialogTitle>{dialogTitle}</DialogTitle>
        </VisuallyHidden>

        {currentPage === 'shortcuts' ? (
          <KeyboardShortcutsView
            commands={allCommands}
            isMobile={isMobile}
            showTerminalShortcuts={isProjectContext && !!onToggleTerminal}
            showSqlEditorShortcuts={isProjectContext}
            showAgentShortcuts
            onBack={() => setPages([])}
            onClose={() => onOpenChange(false)}
            onKeyDown={handleKeyDown}
          />
        ) : currentPage === 'docs' ? (
          <DocsSearchView
            isMobile={isMobile}
            inputRef={docsInputRef}
            onBack={() => setPages([])}
            onClose={() => onOpenChange(false)}
            onKeyDown={handleKeyDown}
            onSelect={handleDocsSelect}
            onOpenShortcuts={() => setPages((p) => [...p, 'shortcuts'])}
          />
        ) : currentPage === 'feedback' ? (
          <CommandCenterFeedbackView
            isMobile={isMobile}
            orgId={orgId ?? project?.teamId}
            projectId={projectId}
            onBack={() => setPages([])}
            onClose={() => onOpenChange(false)}
            onKeyDown={handleKeyDown}
          />
        ) : currentPage === 'support' ? (
          <CommandCenterSupportView
            isMobile={isMobile}
            orgId={orgId ?? project?.teamId}
            onBack={() => setPages([])}
            onClose={() => onOpenChange(false)}
            onKeyDown={handleKeyDown}
          />
        ) : currentPage === 'functions' && context === 'project' ? (
          <Command
            className={cn('bg-transparent', isMobile && 'flex flex-col flex-1')}
            onKeyDown={handleKeyDown}
          >
            <div className="flex items-center border-b border-border [&_[data-slot=command-input-wrapper]]:h-14 [&_[data-slot=command-input-wrapper]]:border-transparent">
              <button
                onClick={() => setPages([])}
                className="ms-3 flex h-6 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
              >
                ← {t('Back')}
              </button>
              <CommandInput
                placeholder={t('Select function to execute...')}
                value={search}
                onValueChange={setSearch}
                className="h-14 border-0 text-foreground placeholder:text-muted-foreground"
              />
              {isMobile && (
                <button
                  onClick={() => onOpenChange(false)}
                  className="me-3 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <X className="h-5 w-5" />
                </button>
              )}
            </div>
            <CommandList
              className={cn('p-2', commandCenterListHeightClass(isMobile))}
            >
              <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                {t('No functions found.')}
              </CommandEmpty>
              <CommandGroup
                heading={t('Functions')}
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
                        {t('Function')}
                      </span>
                    </CommandItem>
                  ))
                ) : (
                  <div className="px-3 py-2.5 text-[13px] text-muted-foreground">
                    {functionsLoading
                      ? t('Loading...')
                      : t('No functions found')}
                  </div>
                )}
              </CommandGroup>
            </CommandList>
          </Command>
        ) : currentPage === 'sql-tabs' && context === 'project' ? (
          <Command
            className={cn('bg-transparent', isMobile && 'flex flex-col flex-1')}
            onKeyDown={handleKeyDown}
          >
            <div className="flex items-center border-b border-border [&_[data-slot=command-input-wrapper]]:h-14 [&_[data-slot=command-input-wrapper]]:border-transparent">
              <button
                onClick={() => setPages([])}
                className="ms-3 flex h-6 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
              >
                ← {t('Back')}
              </button>
              <CommandInput
                placeholder={t('Search query tabs...')}
                value={search}
                onValueChange={setSearch}
                className="h-14 border-0 text-foreground placeholder:text-muted-foreground"
              />
              {isMobile && (
                <button
                  onClick={() => onOpenChange(false)}
                  className="me-3 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <X className="h-5 w-5" />
                </button>
              )}
            </div>
            <CommandList
              className={cn('p-2', commandCenterListHeightClass(isMobile))}
            >
              <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                {t('No query tabs found.')}
              </CommandEmpty>
              <CommandGroup
                heading={t('Query tabs')}
                className="text-muted-foreground"
              >
                {sqlTabPickerItems.length > 0 ? (
                  sqlTabPickerItems.map((tab) => {
                    const allTabs = postgresSqlEditorActions?.tabs ?? []
                    const tabIndex = allTabs.findIndex(
                      (entry) => entry.id === tab.id,
                    )
                    const shortcutLabel =
                      tabIndex >= 0 && tabIndex === allTabs.length - 1
                        ? formatDisplayKeys('mod+9', isMac).join('')
                        : tabIndex >= 0 && tabIndex < 8
                          ? formatDisplayKeys(`mod+${tabIndex + 1}`, isMac).join('')
                          : null

                    return (
                      <CommandItem
                        key={tab.id}
                        value={tab.title}
                        onSelect={() => {
                          postgresSqlEditorActions?.selectTab(tab.id)
                          onOpenChange(false)
                        }}
                        className="group flex cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-muted-foreground data-[selected=true]:bg-accent data-[selected=true]:text-foreground"
                      >
                        <div className="flex h-8 w-8 items-center justify-center rounded-md bg-muted group-data-[selected=true]:bg-accent">
                          <PanelTop className="h-4 w-4" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="truncate text-[13px] font-medium">{tab.title}</p>
                          <p className="text-[11px] text-muted-foreground group-data-[selected=true]:text-foreground/80">
                            {tabIndex >= 0
                              ? `${t('Tab')} ${tabIndex + 1}`
                              : t('Query tab')}
                          </p>
                        </div>
                        {shortcutLabel ? (
                          <span
                            dir="ltr"
                            className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                          >
                            {shortcutLabel}
                          </span>
                        ) : null}
                      </CommandItem>
                    )
                  })
                ) : (
                  <div className="px-3 py-2.5 text-[13px] text-muted-foreground">
                    {t('No query tabs found')}
                  </div>
                )}
              </CommandGroup>
            </CommandList>
          </Command>
        ) : (
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
                  <span className="capitalize">{t(searchScope)}</span>
                  <button
                    onClick={(e) => {
                      e.stopPropagation()
                      setSearchScope(null)
                      setSearch('')
                    }}
                    className="ms-0.5 rounded-sm hover:bg-accent/80 p-0.5 -me-0.5"
                    aria-label={t('Remove scope')}
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
              className={cn('p-2', commandCenterListHeightClass(isMobile))}
            >
              {nonEmptyGroups.length === 0 &&
                (search.trim() || searchScope) && (
                  <div className="py-6 text-center text-[13px] text-muted-foreground">
                    {searchScope && isScopeLoading
                      ? `${t('Searching')} ${t(searchScope)}…`
                      : shouldRunUnifiedResourceSearch && unifiedResourceLoading
                        ? t('Searching resources…')
                        : t('No results found.')}
                  </div>
                )}

              {nonEmptyGroups.map((group, groupIndex) => (
                <div key={group.id}>
                  {groupIndex > 0 && (
                    <CommandSeparator className="my-2 bg-border" />
                  )}
                  <CommandGroup
                    heading={t(group.label)}
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
                            {cmd.iconElement ??
                              (Icon && <Icon className="h-3.5 w-3.5" />)}
                          </div>
                          <div className="flex-1 overflow-hidden">
                            <p className="truncate text-[13px] font-medium">
                              {cmd.resourceKind || cmd.breadcrumbs
                                ? cmd.label
                                : t(cmd.label)}
                            </p>
                            {cmd.breadcrumbs && cmd.breadcrumbs.length > 0 ? (
                              <p className="truncate text-[11px] text-muted-foreground group-data-[selected=true]:text-foreground/80">
                                {cmd.breadcrumbs.map((segment, index) => (
                                  <span key={`${segment}-${index}`}>
                                    {index > 0 ? ' › ' : null}
                                    {t(segment)}
                                  </span>
                                ))}
                              </p>
                            ) : cmd.description ? (
                              <p className="truncate text-[11px] text-muted-foreground group-data-[selected=true]:text-foreground/80">
                                {cmd.resourceKind
                                  ? cmd.description
                                  : t(cmd.description)}
                              </p>
                            ) : null}
                          </div>
                          {cmd.shortcut && !isMobile && (
                            <ShortcutKeyBadges
                              keys={formatDisplayKeys(cmd.shortcut, isMac)}
                              isSequential={
                                !cmd.shortcut.includes('+') &&
                                cmd.shortcut.split(/\s+/).length > 1
                              }
                            />
                          )}
                          {cmd.isResourceSearch && (
                            <ArrowRight className="h-3.5 w-3.5 text-muted-foreground/50" />
                          )}
                          {cmd.resourceKind && (
                            <span
                              className={cn(
                                'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium',
                                'bg-muted text-muted-foreground',
                              )}
                            >
                              {t(PROJECT_RESOURCE_KIND_LABELS[cmd.resourceKind])}
                            </span>
                          )}
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </div>
              ))}
            </CommandList>

            {!isMobile && (
              <CommandCenterListFooter
                onOpenShortcuts={() => setPages([...pages, 'shortcuts'])}
              />
            )}
          </Command>
        )}
      </DialogContent>
    </Dialog>
  )
}

// ─── Internal types & helpers ────────────────────────────────────────────

interface RuntimeCommand {
  id: string
  label: string
  description?: string
  /** Breadcrumb trail segments (recent resources). Each segment is translated. */
  breadcrumbs?: string[]
  icon?: LucideIcon
  /** Custom icon node (e.g. database mascots) overrides `icon` when set. */
  iconElement?: ReactNode
  shortcut?: string
  kind: CommandKind
  group?: string
  keywords?: string[]
  select: () => void
  disabled?: boolean
  /** Used for the "Search X" CTAs to render the right arrow chevron. */
  isResourceSearch?: boolean
  /** Resource type badge for unified project resource search results. */
  resourceKind?: ProjectResourceKind
  /** Internal: navigation target (used by legacy callback bridges). */
  href?: string
}

interface ProjectResourceNavigationHandlers {
  onNavigateToResource?: (section: string, resourceId: string) => void
  onNavigate?: (section: string) => void
  onOpenChange: (open: boolean) => void
}

function projectResourceHitToRuntimeCommand(
  hit: ProjectResourceHit,
  handlers: ProjectResourceNavigationHandlers,
): RuntimeCommand {
  const fallbackSection = hit.section.split('/')[0] ?? hit.section

  return {
    id: hit.id,
    label: hit.label,
    description: hit.description,
    icon: RESOURCE_KIND_ICONS[hit.kind],
    kind: 'action',
    resourceKind: hit.kind,
    select: () => {
      if (handlers.onNavigateToResource) {
        handlers.onNavigateToResource(hit.section, hit.resourceId)
      } else {
        handlers.onNavigate?.(fallbackSection)
      }
      handlers.onOpenChange(false)
    },
  }
}

function toRuntimeCommand(
  entry: CommandEntry,
  ctx: CommandContext,
): RuntimeCommand {
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
