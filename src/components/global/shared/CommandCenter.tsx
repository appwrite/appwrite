import { useState, useEffect, useMemo, useCallback } from 'react'
import { cn } from '@/lib/utils'
import { useIsMobile } from '@/hooks/use-mobile'
import {
  LayoutDashboard,
  Database,
  Users,
  Folder,
  Zap,
  MessageSquare,
  Settings,
  BarChart3,
  Key,
  Globe,
  Plug,
  Activity,
  FileText,
  FolderOpen,
  Play,
  UserPlus,
  Upload,
  Terminal,
  Keyboard,
  ArrowRight,
  Clock,
  Plus,
  Building2,
  CreditCard,
  ShieldCheck,
  X,
  Sparkles,
  BarChart2,
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
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'

// Command types
type CommandType =
  | 'navigation'
  | 'action'
  | 'create'
  | 'search'
  | 'recent'
  | 'settings'

interface CommandItemType {
  id: string
  label: string
  description?: string
  icon: LucideIcon
  type: CommandType
  shortcut?: string
  keywords?: string[]
  action: () => void
  disabled?: boolean
}

interface CommandGroup {
  id: string
  label: string
  commands: CommandItemType[]
}

type CommandCenterContext = 'project' | 'org'

interface CommandCenterProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onNavigate?: (section: string) => void
  context?: CommandCenterContext
  onOrgNavigate?: (tab: string) => void
  projectId?: string | null
  orgId?: string | null
}

export function CommandCenter({
  open,
  onOpenChange,
  onNavigate,
  context = 'project',
  onOrgNavigate,
  projectId,
  orgId,
}: CommandCenterProps) {
  const [search, setSearch] = useState('')
  const [pages, setPages] = useState<string[]>([])
  const [searchScope, setSearchScope] = useState<
    | 'databases'
    | 'users'
    | 'teams'
    | 'buckets'
    | 'functions'
    | 'projects'
    | null
  >(null)
  const currentPage = pages[pages.length - 1]
  const isMobile = useIsMobile()
  const { features } = useConsoleProfile()

  // Fetch resources based on context - only when there's a search query or scope
  const hasSearch = search.trim().length > 0
  const hasScope = searchScope !== null
  const shouldFetch = hasSearch || hasScope
  const isOrgContext = context === 'org'
  const isProjectContext = context === 'project'

  // Org context: fetch projects (only if searching for projects)
  const { projects: orgProjects, isLoading: orgProjectsLoading } =
    useProjectsForTeam(
      isOrgContext && orgId && shouldFetch && searchScope === 'projects'
        ? orgId
        : null,
      0,
      100,
      shouldFetch && searchScope === 'projects' ? search : undefined,
    )

  // Project context: fetch databases, users, teams, buckets, functions (only if searching for that type)
  const { databases: projectDatabases, isLoading: databasesLoading } =
    useProjectDatabases(
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

  // Reset state when dialog closes
  useEffect(() => {
    if (!open) {
      setSearch('')
      setPages([])
      setSearchScope(null)
    }
  }, [open])

  // Organization navigation commands
  const orgNavigationCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'nav-projects',
        label: 'Go to Projects',
        description: 'View all projects',
        icon: FolderOpen,
        type: 'navigation',
        shortcut: 'G P',
        keywords: ['home', 'main', 'list'],
        action: () => {
          onOrgNavigate?.('projects')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-org-settings',
        label: 'Go to Settings',
        description: 'Organization settings',
        icon: Settings,
        type: 'navigation',
        shortcut: 'G S',
        keywords: ['config', 'preferences', 'options'],
        action: () => {
          onOrgNavigate?.('settings')
          onOpenChange(false)
        },
      },
      ...(features.domains
        ? [
            {
              id: 'nav-domains',
              label: 'Go to Domains',
              description: 'Manage custom domains',
              icon: Globe,
              type: 'navigation' as const,
              shortcut: 'G D',
              keywords: ['dns', 'url', 'hosting'],
              action: () => {
                onOrgNavigate?.('domains')
                onOpenChange(false)
              },
            },
          ]
        : []),
      ...(features.orgRoles
        ? [
            {
              id: 'nav-members',
              label: 'Go to Members',
              description: 'Team members and roles',
              icon: Users,
              type: 'navigation' as const,
              shortcut: 'G M',
              keywords: ['team', 'users', 'roles', 'permissions'],
              action: () => {
                onOrgNavigate?.('settings/members')
                onOpenChange(false)
              },
            },
          ]
        : []),
      ...(features.billing
        ? [
            {
              id: 'nav-billing',
              label: 'Go to Billing',
              description: 'Billing and subscription',
              icon: CreditCard,
              type: 'navigation' as const,
              shortcut: 'G B',
              keywords: ['payment', 'subscription', 'invoice'],
              action: () => {
                onOrgNavigate?.('settings/billing')
                onOpenChange(false)
              },
            },
          ]
        : []),
      ...(features.orgCloudSettings
        ? [
            {
              id: 'nav-compliance',
              label: 'Go to Compliance',
              description: 'DPA, BAA, SOC 2',
              icon: ShieldCheck,
              type: 'navigation' as const,
              shortcut: 'G C',
              keywords: ['compliance', 'dpa', 'baa', 'soc2', 'hipaa', 'gdpr'],
              action: () => {
                onOrgNavigate?.('settings/compliance')
                onOpenChange(false)
              },
            },
          ]
        : []),
    ],
    [onOrgNavigate, onOpenChange, features.domains, features.orgRoles, features.billing, features.orgCloudSettings],
  )

  // Organization create commands
  const orgCreateCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'create-project',
        label: 'Create Project',
        description: 'Create a new project',
        icon: Plus,
        type: 'create',
        shortcut: 'C P',
        keywords: ['new', 'add'],
        action: () => {
          onOpenChange(false)
        },
      },
      {
        id: 'create-team',
        label: 'Create Team',
        description: 'Create a new team',
        icon: Building2,
        type: 'create',
        shortcut: 'C T',
        keywords: ['new', 'add', 'organization'],
        action: () => {
          onOpenChange(false)
        },
      },
      {
        id: 'invite-member',
        label: 'Invite Member',
        description: 'Invite a team member',
        icon: UserPlus,
        type: 'create',
        shortcut: 'C M',
        keywords: ['new', 'add', 'user'],
        action: () => {
          onOpenChange(false)
        },
      },
    ],
    [onOpenChange],
  )

  // Organization resource search
  const orgResourceCommands: CommandItemType[] = useMemo(() => {
    const items: CommandItemType[] = []

    // Projects
    if (orgProjects && !orgProjectsLoading) {
      orgProjects.forEach((project) => {
        items.push({
          id: `project-${project.$id}`,
          label: project.name,
          description: `Project · ${project.region || 'unknown'}`,
          icon: FolderOpen,
          type: 'search',
          keywords: ['project'],
          action: () => {
            // Navigate to project
            window.location.href = `/projects/${project.$id}`
          },
        })
      })
    }

    return items
  }, [orgProjects, orgProjectsLoading])

  // Project navigation commands
  const navigationCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'nav-overview',
        label: 'Go to Overview',
        description: 'Dashboard overview',
        icon: LayoutDashboard,
        type: 'navigation',
        shortcut: 'G O',
        keywords: ['home', 'dashboard', 'main'],
        action: () => {
          onNavigate?.('overview')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-databases',
        label: 'Go to Databases',
        description: 'Manage databases and collections',
        icon: Database,
        type: 'navigation',
        shortcut: 'G D',
        keywords: ['db', 'collections', 'documents'],
        action: () => {
          onNavigate?.('databases')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-auth',
        label: 'Go to Auth',
        description: 'Users and authentication',
        icon: Users,
        type: 'navigation',
        shortcut: 'G A',
        keywords: ['users', 'authentication', 'login'],
        action: () => {
          onNavigate?.('auth')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-storage',
        label: 'Go to Storage',
        description: 'Files and buckets',
        icon: Folder,
        type: 'navigation',
        shortcut: 'G S',
        keywords: ['files', 'buckets', 'uploads'],
        action: () => {
          onNavigate?.('storage')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-functions',
        label: 'Go to Functions',
        description: 'Serverless functions',
        icon: Zap,
        type: 'navigation',
        shortcut: 'G F',
        keywords: ['serverless', 'lambda', 'code'],
        action: () => {
          onNavigate?.('functions')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-messaging',
        label: 'Go to Messaging',
        description: 'Push notifications and messages',
        icon: MessageSquare,
        type: 'navigation',
        keywords: ['notifications', 'push', 'sms'],
        action: () => {
          onNavigate?.('messaging')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-apps',
        label: 'Go to Apps',
        description: 'Apps and platforms',
        icon: Plug,
        type: 'navigation',
        keywords: ['connect', 'apps', 'platforms'],
        action: () => {
          onNavigate?.('apps')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-api-keys',
        label: 'Go to API Keys',
        description: 'Manage API keys',
        icon: Key,
        type: 'navigation',
        keywords: ['keys', 'tokens', 'secrets'],
        action: () => {
          onNavigate?.('api-keys')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-sites',
        label: 'Go to Sites',
        description: 'Deployed websites',
        icon: Globe,
        type: 'navigation',
        keywords: ['hosting', 'deploy', 'web'],
        action: () => {
          onNavigate?.('sites')
          onOpenChange(false)
        },
      },
      ...(features.activity
        ? [
            {
              id: 'nav-activity',
              label: 'Go to Activity',
              description: 'Activity logs',
              icon: Activity,
              type: 'navigation' as const,
              keywords: ['logs', 'events', 'history'],
              action: () => {
                onNavigate?.('activity')
                onOpenChange(false)
              },
            },
          ]
        : []),
      ...(features.usageStats
        ? [
            {
              id: 'nav-usage',
              label: 'Go to Usage',
              description: 'Usage statistics',
              icon: BarChart3,
              type: 'navigation' as const,
              keywords: ['stats', 'metrics', 'usage'],
              action: () => {
                onNavigate?.('usage')
                onOpenChange(false)
              },
            },
          ]
        : []),
      {
        id: 'nav-analytics',
        label: 'Go to Analytics',
        description: 'Website analytics and insights',
        icon: BarChart2,
        type: 'navigation',
        keywords: ['analytics', 'insights', 'tracking', 'website'],
        action: () => {
          onNavigate?.('analytics')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-imagine',
        label: 'Go to Imagine',
        description: 'AI-powered project generation',
        icon: Sparkles,
        type: 'navigation',
        keywords: ['imagine', 'ai', 'generate', 'create'],
        action: () => {
          onNavigate?.('imagine')
          onOpenChange(false)
        },
      },
      {
        id: 'nav-settings',
        label: 'Go to Settings',
        description: 'Project settings',
        icon: Settings,
        type: 'navigation',
        shortcut: 'G ,',
        keywords: ['config', 'preferences', 'options'],
        action: () => {
          onNavigate?.('settings')
          onOpenChange(false)
        },
      },
    ],
    [onNavigate, onOpenChange, features.activity, features.usageStats],
  )

  // Create commands
  const createCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'create-database',
        label: 'Create Database',
        description: 'Create a new database',
        icon: Database,
        type: 'create',
        shortcut: 'C D',
        keywords: ['new', 'add'],
        action: () => {
          onNavigate?.('databases')
          onOpenChange(false)
        },
      },
      {
        id: 'create-collection',
        label: 'Create Collection',
        description: 'Create a new collection',
        icon: FolderOpen,
        type: 'create',
        shortcut: 'C C',
        keywords: ['new', 'add', 'table'],
        action: () => {
          onNavigate?.('databases')
          onOpenChange(false)
        },
      },
      {
        id: 'create-document',
        label: 'Create Document',
        description: 'Create a new document',
        icon: FileText,
        type: 'create',
        keywords: ['new', 'add', 'record'],
        action: () => {
          onNavigate?.('databases')
          onOpenChange(false)
        },
      },
      {
        id: 'create-bucket',
        label: 'Create Bucket',
        description: 'Create a new storage bucket',
        icon: Folder,
        type: 'create',
        shortcut: 'C B',
        keywords: ['new', 'add', 'storage'],
        action: () => {
          onNavigate?.('storage')
          onOpenChange(false)
        },
      },
      {
        id: 'create-function',
        label: 'Create Function',
        description: 'Create a new serverless function',
        icon: Zap,
        type: 'create',
        shortcut: 'C F',
        keywords: ['new', 'add', 'serverless'],
        action: () => {
          onNavigate?.('functions')
          onOpenChange(false)
        },
      },
      {
        id: 'create-user',
        label: 'Create User',
        description: 'Create a new user',
        icon: UserPlus,
        type: 'create',
        shortcut: 'C U',
        keywords: ['new', 'add', 'account'],
        action: () => {
          onNavigate?.('auth')
          onOpenChange(false)
        },
      },
      {
        id: 'upload-file',
        label: 'Upload File',
        description: 'Upload a file to storage',
        icon: Upload,
        type: 'create',
        keywords: ['new', 'add', 'import'],
        action: () => {
          onNavigate?.('storage')
          onOpenChange(false)
        },
      },
    ],
    [onNavigate, onOpenChange],
  )

  // Quick actions
  const actionCommands: CommandItemType[] = useMemo(() => {
    const commands: CommandItemType[] = [
      {
        id: 'action-execute-function',
        label: 'Execute Function',
        description: 'Run a serverless function',
        icon: Play,
        type: 'action',
        keywords: ['run', 'trigger', 'invoke'],
        action: () => setPages([...pages, 'functions']),
      },
      ...(features.activity
        ? [
            {
              id: 'action-view-logs',
              label: 'View Logs',
              description: 'View activity logs',
              icon: Terminal,
              type: 'action' as const,
              shortcut: isMobile ? undefined : ('L' as const),
              keywords: ['console', 'debug', 'output'],
              action: () => {
                onNavigate?.('activity')
                onOpenChange(false)
              },
            },
          ]
        : []),
    ]

    // Only show keyboard shortcuts on non-touch devices
    if (!isMobile) {
      commands.push({
        id: 'action-keyboard-shortcuts',
        label: 'Keyboard Shortcuts',
        description: 'View all keyboard shortcuts',
        icon: Keyboard,
        type: 'action',
        shortcut: '?',
        keywords: ['help', 'keys', 'hotkeys'],
        action: () => setPages([...pages, 'shortcuts']),
      })
    }

    return commands
  }, [onNavigate, onOpenChange, pages, isMobile, features.activity])

  // Org-specific actions
  const orgActionCommands: CommandItemType[] = useMemo(() => {
    const commands: CommandItemType[] = []

    // Only show keyboard shortcuts on non-touch devices
    if (!isMobile) {
      commands.push({
        id: 'action-keyboard-shortcuts',
        label: 'Keyboard Shortcuts',
        description: 'View all keyboard shortcuts',
        icon: Keyboard,
        type: 'action',
        shortcut: '?',
        keywords: ['help', 'keys', 'hotkeys'],
        action: () => setPages([...pages, 'shortcuts']),
      })
    }

    return commands
  }, [pages, isMobile])

  // Search commands - CTAs to search for specific resources
  const searchCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'search-databases',
        label: 'Search Databases',
        description: 'Find databases in this project',
        icon: Database,
        type: 'search',
        keywords: ['database', 'db', 'search'],
        action: () => {
          setSearchScope('databases')
          setSearch('')
        },
      },
      {
        id: 'search-users',
        label: 'Search Users',
        description: 'Find users in this project',
        icon: Users,
        type: 'search',
        keywords: ['user', 'account', 'search'],
        action: () => {
          setSearchScope('users')
          setSearch('')
        },
      },
      {
        id: 'search-teams',
        label: 'Search Teams',
        description: 'Find teams in this project',
        icon: Building2,
        type: 'search',
        keywords: ['team', 'organization', 'search'],
        action: () => {
          setSearchScope('teams')
          setSearch('')
        },
      },
      {
        id: 'search-buckets',
        label: 'Search Buckets',
        description: 'Find storage buckets in this project',
        icon: Folder,
        type: 'search',
        keywords: ['bucket', 'storage', 'file', 'search'],
        action: () => {
          setSearchScope('buckets')
          setSearch('')
        },
      },
      {
        id: 'search-functions',
        label: 'Search Functions',
        description: 'Find functions in this project',
        icon: Zap,
        type: 'search',
        keywords: ['function', 'serverless', 'lambda', 'search'],
        action: () => {
          setSearchScope('functions')
          setSearch('')
        },
      },
    ],
    [],
  )

  // Org search commands - CTAs to search for projects
  const orgSearchCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'search-projects',
        label: 'Search Projects',
        description: 'Find projects in this organization',
        icon: FolderOpen,
        type: 'search',
        keywords: ['project', 'search'],
        action: () => {
          setSearchScope('projects')
          setSearch('')
        },
      },
    ],
    [],
  )

  // Search resources (databases, users, teams, buckets, functions)
  // Only show resources of the active search scope
  const resourceCommands: CommandItemType[] = useMemo(() => {
    const items: CommandItemType[] = []

    // Only show resources if we have an active search scope
    if (!searchScope) return items

    // Databases
    if (searchScope === 'databases' && projectDatabases && !databasesLoading) {
      projectDatabases.forEach((db: unknown) => {
        items.push({
          id: `db-${db.$id}`,
          label: db.name,
          description: `Database · ${db.$id}`,
          icon: Database,
          type: 'search',
          keywords: ['database', 'db'],
          action: () => {
            onNavigate?.('databases')
            onOpenChange(false)
          },
        })
      })
    }

    // Users
    if (searchScope === 'users' && projectUsers && !usersLoading) {
      projectUsers.forEach((user) => {
        items.push({
          id: `user-${user.$id}`,
          label: user.name || user.email || 'Unknown',
          description: `User · ${user.email || 'No email'}`,
          icon: Users,
          type: 'search',
          keywords: ['user', 'account', 'auth'],
          action: () => {
            onNavigate?.('auth')
            onOpenChange(false)
          },
        })
      })
    }

    // Teams
    if (searchScope === 'teams' && projectTeams && !teamsLoading) {
      projectTeams.forEach((team) => {
        items.push({
          id: `team-${team.id}`,
          label: team.name,
          description: `Team · ${team.id}`,
          icon: Building2,
          type: 'search',
          keywords: ['team', 'organization'],
          action: () => {
            onNavigate?.('auth')
            onOpenChange(false)
          },
        })
      })
    }

    // Buckets
    if (searchScope === 'buckets' && projectBuckets && !bucketsLoading) {
      projectBuckets.forEach((bucket) => {
        items.push({
          id: `bucket-${bucket.$id}`,
          label: bucket.name,
          description: `Bucket · ${bucket.$id}`,
          icon: Folder,
          type: 'search',
          keywords: ['bucket', 'storage', 'file'],
          action: () => {
            onNavigate?.('storage')
            onOpenChange(false)
          },
        })
      })
    }

    // Functions
    if (searchScope === 'functions' && projectFunctions && !functionsLoading) {
      projectFunctions.forEach((fn) => {
        items.push({
          id: `fn-${fn.$id}`,
          label: fn.name,
          description: `Function · ${fn.runtime || 'unknown'}`,
          icon: Zap,
          type: 'search',
          keywords: ['function', 'serverless', 'lambda'],
          action: () => {
            onNavigate?.('functions')
            onOpenChange(false)
          },
        })
      })
    }

    // Projects (org context)
    if (searchScope === 'projects' && orgProjects && !orgProjectsLoading) {
      orgProjects.forEach((project) => {
        items.push({
          id: `project-${project.$id}`,
          label: project.name,
          description: `Project · ${project.region || 'unknown'}`,
          icon: FolderOpen,
          type: 'search',
          keywords: ['project'],
          action: () => {
            window.location.href = `/projects/${project.$id}`
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
    orgProjects,
    orgProjectsLoading,
    onNavigate,
    onOpenChange,
  ])

  // Recent items (simulated)
  const recentCommands: CommandItemType[] = useMemo(
    () => [
      {
        id: 'recent-1',
        label: 'Production Database',
        description: 'Opened 2 hours ago',
        icon: Clock,
        type: 'recent',
        action: () => {
          onNavigate?.('databases')
          onOpenChange(false)
        },
      },
      {
        id: 'recent-2',
        label: 'send-notification',
        description: 'Executed 3 hours ago',
        icon: Clock,
        type: 'recent',
        action: () => {
          onNavigate?.('functions')
          onOpenChange(false)
        },
      },
    ],
    [onNavigate, onOpenChange],
  )

  // Org recent items
  const orgRecentCommands: CommandItemType[] = useMemo(() => {
    const items: CommandItemType[] = []

    // Show up to 2 recent projects if available
    if (orgProjects && orgProjects.length > 0) {
      orgProjects.slice(0, 2).forEach((project, index) => {
        items.push({
          id: `recent-project-${index + 1}`,
          label: project.name,
          description: index === 0 ? 'Opened 1 hour ago' : 'Opened 3 hours ago',
          icon: Clock,
          type: 'recent',
          action: () => {
            window.location.href = `/projects/${project.$id}`
          },
        })
      })
    }

    // If no projects, show placeholder
    if (items.length === 0) {
      items.push({
        id: 'recent-project-placeholder',
        label: 'No recent projects',
        description: 'Projects you visit will appear here',
        icon: Clock,
        type: 'recent',
        action: () => {
          onOrgNavigate?.('projects')
          onOpenChange(false)
        },
      })
    }

    return items
  }, [orgProjects, onOrgNavigate, onOpenChange])

  // Keyboard shortcuts reference
  const shortcutGroups =
    context === 'org'
      ? [
          {
            label: 'Navigation',
            shortcuts: [
              { keys: ['G', 'P'], description: 'Go to Projects' },
              { keys: ['G', 'S'], description: 'Go to Settings' },
              { keys: ['G', 'D'], description: 'Go to Domains' },
              { keys: ['G', 'M'], description: 'Go to Members' },
              { keys: ['G', 'B'], description: 'Go to Billing' },
              { keys: ['G', 'C'], description: 'Go to Compliance' },
            ],
          },
          {
            label: 'Create',
            shortcuts: [
              { keys: ['C', 'P'], description: 'Create Project' },
              { keys: ['C', 'T'], description: 'Create Team' },
              { keys: ['C', 'M'], description: 'Invite Member' },
            ],
          },
          {
            label: 'Actions',
            shortcuts: [
              { keys: ['⌘', 'K'], description: 'Open Command Center' },
              { keys: ['?'], description: 'Show Keyboard Shortcuts' },
              { keys: ['Esc'], description: 'Close / Go Back' },
              { keys: ['/'], description: 'Focus Search' },
            ],
          },
        ]
      : [
          {
            label: 'Navigation',
            shortcuts: [
              { keys: ['G', 'O'], description: 'Go to Overview' },
              { keys: ['G', 'D'], description: 'Go to Databases' },
              { keys: ['G', 'A'], description: 'Go to Auth' },
              { keys: ['G', 'S'], description: 'Go to Storage' },
              { keys: ['G', 'F'], description: 'Go to Functions' },
              { keys: ['G', 'U'], description: 'Go to Usage' },
              { keys: ['G', ','], description: 'Go to Settings' },
            ],
          },
          {
            label: 'Create',
            shortcuts: [
              { keys: ['C', 'D'], description: 'Create Database' },
              { keys: ['C', 'C'], description: 'Create Collection' },
              { keys: ['C', 'B'], description: 'Create Bucket' },
              { keys: ['C', 'F'], description: 'Create Function' },
              { keys: ['C', 'U'], description: 'Create User' },
            ],
          },
          {
            label: 'Actions',
            shortcuts: [
              { keys: ['⌘', 'K'], description: 'Open Command Center' },
              { keys: ['?'], description: 'Show Keyboard Shortcuts' },
              { keys: ['L'], description: 'View Logs' },
              { keys: ['Esc'], description: 'Close / Go Back' },
              { keys: ['/'], description: 'Focus Search' },
            ],
          },
        ]

  // Filter commands based on search and context
  const filteredGroups = useMemo(() => {
    const isOrgContext = context === 'org'

    // If we have an active search scope, show resources for that scope
    if (searchScope) {
      const scopeLabel =
        searchScope === 'databases'
          ? 'Databases'
          : searchScope === 'users'
            ? 'Users'
            : searchScope === 'teams'
              ? 'Teams'
              : searchScope === 'buckets'
                ? 'Buckets'
                : searchScope === 'functions'
                  ? 'Functions'
                  : 'Projects'

      return [
        {
          id: 'resources',
          label: scopeLabel,
          commands: resourceCommands,
        },
      ]
    }

    if (!search) {
      if (isOrgContext) {
        return [
          { id: 'recent', label: 'Recent', commands: orgRecentCommands },
          {
            id: 'navigation',
            label: 'Navigation',
            commands: orgNavigationCommands,
          },
          { id: 'search', label: 'Search', commands: orgSearchCommands },
          { id: 'create', label: 'Create', commands: orgCreateCommands },
          { id: 'actions', label: 'Actions', commands: orgActionCommands },
        ]
      }
      return [
        { id: 'recent', label: 'Recent', commands: recentCommands },
        {
          id: 'navigation',
          label: 'Navigation',
          commands: navigationCommands.slice(0, 8),
        },
        { id: 'search', label: 'Search', commands: searchCommands },
        { id: 'create', label: 'Create', commands: createCommands.slice(0, 4) },
        { id: 'actions', label: 'Actions', commands: actionCommands },
      ]
    }

    const searchLower = search.toLowerCase()
    const allCommands = isOrgContext
      ? [
          ...orgNavigationCommands,
          ...orgCreateCommands,
          ...orgActionCommands,
          ...orgResourceCommands,
        ]
      : [
          ...navigationCommands,
          ...createCommands,
          ...actionCommands,
          ...resourceCommands,
        ]

    const filtered = allCommands.filter((cmd) => {
      const matchLabel = cmd.label.toLowerCase().includes(searchLower)
      const matchDescription = cmd.description
        ?.toLowerCase()
        .includes(searchLower)
      const matchKeywords = cmd.keywords?.some((k) =>
        k.toLowerCase().includes(searchLower),
      )
      return matchLabel || matchDescription || matchKeywords
    })

    if (filtered.length === 0) return []

    // Group by type
    const grouped: Record<string, CommandItemType[]> = {}
    filtered.forEach((cmd) => {
      const group =
        cmd.type === 'search'
          ? 'Resources'
          : cmd.type === 'navigation'
            ? 'Navigation'
            : cmd.type === 'create'
              ? 'Create'
              : 'Actions'
      if (!grouped[group]) grouped[group] = []
      grouped[group].push(cmd)
    })

    return Object.entries(grouped).map(([label, commands]) => ({
      id: label.toLowerCase(),
      label,
      commands,
    }))
  }, [
    search,
    searchScope,
    context,
    navigationCommands,
    createCommands,
    actionCommands,
    resourceCommands,
    recentCommands,
    orgNavigationCommands,
    orgCreateCommands,
    orgActionCommands,
    orgResourceCommands,
    orgRecentCommands,
    orgSearchCommands,
    searchCommands,
  ])

  // Handle keyboard navigation within pages and scope removal
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      // Remove scope with Backspace when search is empty
      if (e.key === 'Backspace' && !search && searchScope) {
        e.preventDefault()
        setSearchScope(null)
        return
      }

      // Remove scope with Escape
      if (e.key === 'Escape' && searchScope) {
        e.preventDefault()
        setSearchScope(null)
        setSearch('')
        return
      }

      // Navigate back in pages
      if (
        e.key === 'Backspace' &&
        !search &&
        !searchScope &&
        pages.length > 0
      ) {
        e.preventDefault()
        setPages(pages.slice(0, -1))
      }
      if (e.key === 'Escape' && !searchScope && pages.length > 0) {
        e.preventDefault()
        setPages(pages.slice(0, -1))
      }
    },
    [search, searchScope, pages],
  )

  // Render shortcuts page
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
                Keyboard Shortcuts
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
                {shortcutGroups.map((group) => (
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

  // Render functions page (for execute function) - only in project context
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
            <DialogTitle>Execute Function</DialogTitle>
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

  // Main command center
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
          <DialogTitle>Command Center</DialogTitle>
        </VisuallyHidden>
        <Command
          className={cn('bg-transparent', isMobile && 'flex flex-col flex-1')}
          onKeyDown={handleKeyDown}
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
              placeholder={
                searchScope
                  ? `Search ${searchScope}...`
                  : context === 'org'
                    ? 'Search projects, teams, or commands...'
                    : 'Type a command or search...'
              }
              value={search}
              onValueChange={setSearch}
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
              isMobile ? 'flex-1 max-h-none' : 'max-h-[400px]',
            )}
          >
            <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
              No results found.
            </CommandEmpty>

            {filteredGroups.map((group, groupIndex) => (
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
                        value={`${cmd.label} ${cmd.description || ''} ${cmd.keywords?.join(' ') || ''}`}
                        onSelect={cmd.action}
                        disabled={cmd.disabled}
                        className="group flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-muted-foreground data-[selected=true]:bg-accent data-[selected=true]:text-foreground"
                      >
                        <div className="flex h-7 w-7 items-center justify-center rounded-md bg-muted group-data-[selected=true]:bg-accent">
                          <Icon className="h-3.5 w-3.5" />
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
                        {cmd.type === 'search' && (
                          <ArrowRight className="h-3.5 w-3.5 text-muted-foreground/50" />
                        )}
                      </CommandItem>
                    )
                  })}
                </CommandGroup>
              </div>
            ))}
          </CommandList>

          {/* Footer with hints - hidden on mobile */}
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
