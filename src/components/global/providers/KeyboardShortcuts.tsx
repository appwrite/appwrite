import {
  createContext,
  useContext,
  useState,
  useCallback,
  useEffect,
  useMemo,
  type ReactNode,
  type ComponentProps,
} from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useSequentialShortcuts } from '@/hooks/use-keyboard-shortcuts'
import { useGlobalCommandShortcuts } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { storageHomeNavigation } from '@/lib/storage-routes'
import {
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import {
  canCreateBucket,
  canCreateDatabase,
  canCreateFunction,
  canCreateSite,
  canCreateTeam,
  canCreateUser,
  canSeeActivityNav,
  canSeeProjectNavItem,
  canSeeUsageNav,
  canShowConnectSection,
  canShowProjectSettings,
} from '@/lib/console-access-checks'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useCliShellOptional } from '@/components/global/cli-shell/CliShellProvider'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import {
  registerCommandCenterOpener,
  type CommandCenterPage,
} from '@/lib/command-center/opener-bridge'

export type { CommandCenterPage }

interface KeyboardShortcutsContextValue {
  openCommandCenter: () => void
  /** Open Command Center directly on a named sub-page (protocol / agent). */
  openCommandCenterPage: (page: CommandCenterPage) => void
  closeCommandCenter: () => void
  isCommandCenterOpen: boolean
}

const KeyboardShortcutsContext =
  createContext<KeyboardShortcutsContextValue | null>(null)

/** Fallback when outside a provider (agent pane / pages without a CC host). */
const defaultContextValue: KeyboardShortcutsContextValue = {
  openCommandCenter: () => {},
  openCommandCenterPage: () => {},
  closeCommandCenter: () => {},
  isCommandCenterOpen: false,
}

export function useKeyboardShortcutsContext() {
  const context = useContext(KeyboardShortcutsContext)
  // Return default context if not within provider (e.g., agent pane / org overview)
  return context ?? defaultContextValue
}

interface KeyboardShortcutsProviderProps {
  children: ReactNode
  projectId: string
  onFocusSearch?: () => void
}

export function KeyboardShortcutsProvider({
  children,
  projectId,
  onFocusSearch,
}: KeyboardShortcutsProviderProps) {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [initialSubPage, setInitialSubPage] = useState<string | null>(null)
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const cliShell = useCliShellOptional()
  const toggleTerminal = cliShell?.toggle
  const projectConnect = useProjectConnectDialog()
  const openConnectMcp = useCallback(() => {
    projectConnect?.openConnect('mcp')
  }, [projectConnect])

  const navigateToSection = useCallback(
    (section: string) => {
      if (section === 'overview') {
        navigate({ to: '/projects/$projectId', params: { projectId } })
        return
      }
      if (section === 'storage') {
        const storageNav = storageHomeNavigation(projectId)
        navigate({ to: storageNav.to, params: storageNav.params })
        return
      }
      if (section === 'apps') {
        navigate({ to: '/projects/$projectId/apps', params: { projectId } })
        return
      }
      if (section === 'api-keys') {
        navigate({ to: '/projects/$projectId/api-keys', params: { projectId } })
        return
      }
      if (section === 'projects') {
        const teamId = project?.teamId
        if (teamId) {
          navigate({ to: '/organizations/$orgId', params: { orgId: teamId } })
        }
        return
      }
      if (section === 'explorer') {
        navigate({ to: '/projects/$projectId/explorer', params: { projectId } })
        return
      }
      if (section === 'auth') {
        navigate({ to: '/projects/$projectId/auth', params: { projectId } })
        return
      }
      if (section === 'databases') {
        navigate({ to: '/projects/$projectId/databases', params: { projectId } })
        return
      }
      if (section === 'functions') {
        navigate({ to: '/projects/$projectId/functions', params: { projectId } })
        return
      }
      if (section === 'messaging') {
        navigate({ to: '/projects/$projectId/messaging', params: { projectId } })
        return
      }
      if (section === 'sites') {
        navigate({ to: '/projects/$projectId/sites', params: { projectId } })
        return
      }
      if (section === 'activity') {
        navigate({ to: '/projects/$projectId/activity', params: { projectId } })
        return
      }
      if (section === 'usage') {
        navigate({ to: '/projects/$projectId/usage', params: { projectId } })
        return
      }
      if (section === 'settings') {
        navigate({ to: '/projects/$projectId/settings', params: { projectId } })
        return
      }
      navigate({ to: `/projects/${projectId}/${section}` as never })
    },
    [navigate, projectId, project?.teamId],
  )

  const onNavigateToResource = useCallback(
    (section: string, resourceId: string) => {
      if (section === 'databases') {
        navigate({
          to: '/projects/$projectId/databases/$databaseId',
          params: { projectId, databaseId: resourceId },
        })
      } else if (section === 'auth/users') {
        navigate({
          to: '/projects/$projectId/auth/users/$userId',
          params: { projectId, userId: resourceId },
        })
      } else if (section === 'auth/teams') {
        navigate({
          to: '/projects/$projectId/auth/teams/$teamId',
          params: { projectId, teamId: resourceId },
        })
      } else if (section === 'storage') {
        navigate({
          to: '/projects/$projectId/storage/$bucketId',
          params: { projectId, bucketId: resourceId },
        })
      } else if (section === 'functions') {
        navigate({
          to: '/projects/$projectId/functions/$functionId',
          params: { projectId, functionId: resourceId },
        })
      } else if (section === 'sites') {
        navigate({
          to: '/projects/$projectId/sites/$siteId',
          params: { projectId, siteId: resourceId },
        })
      } else if (section === 'messaging/messages') {
        navigate({
          to: '/projects/$projectId/messaging/$messageId',
          params: { projectId, messageId: resourceId },
        })
      } else if (section === 'messaging/topics') {
        navigate({
          to: '/projects/$projectId/messaging/topics/$topicId',
          params: { projectId, topicId: resourceId },
        })
      } else if (section === 'messaging/providers') {
        navigate({
          to: '/projects/$projectId/messaging/providers/$providerId',
          params: { projectId, providerId: resourceId },
        })
      }
    },
    [navigate, projectId],
  )

  const onCreateResource = useCallback(
    (type: 'database' | 'bucket' | 'user' | 'team' | 'function' | 'site') => {
      if (type === 'database') {
        navigate({
          to: '/projects/$projectId/databases',
          params: { projectId },
          search: { create: 'database' },
        })
      } else if (type === 'bucket') {
        navigate({
          to: '/projects/$projectId/storage/',
          params: { projectId },
          search: { create: 'bucket' },
        })
      } else if (type === 'user') {
        navigate({
          to: '/projects/$projectId/auth',
          params: { projectId },
          search: { create: 'user' },
        })
      } else if (type === 'team') {
        navigate({
          to: '/projects/$projectId/auth',
          params: { projectId },
          search: { create: 'team' },
        })
      } else if (type === 'function') {
        navigate({
          to: '/projects/$projectId/functions/create',
          params: { projectId },
        })
      } else if (type === 'site') {
        navigate({
          to: '/projects/$projectId/sites/create',
          params: { projectId },
        })
      }
    },
    [navigate, projectId],
  )

  const openCommandCenter = useCallback(() => {
    setInitialSubPage(null)
    setCommandCenterOpen(true)
  }, [])

  const openCommandCenterPage = useCallback((page: CommandCenterPage) => {
    setInitialSubPage(page)
    setCommandCenterOpen(true)
  }, [])

  const openShortcutsHelp = useCallback(() => {
    openCommandCenterPage('shortcuts')
  }, [openCommandCenterPage])

  // Agent pane is a sibling of this provider; register so protocol effects can open CC.
  useEffect(() => {
    return registerCommandCenterOpener((page) => {
      setInitialSubPage(page)
      setCommandCenterOpen(true)
    })
  }, [])

  const handleCommandCenterOpenChange = useCallback((open: boolean) => {
    setCommandCenterOpen(open)
    if (!open) {
      setInitialSubPage(null)
    }
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen,
    onOpenCommandCenter: () => {
      if (onFocusSearch) {
        onFocusSearch()
      } else {
        openCommandCenter()
      }
    },
    onOpenShortcutsHelp: openShortcutsHelp,
  })

  // Sequential shortcuts for navigation (vim-style)
  const sequentialShortcuts = useMemo(() => {
    const shortcuts: Record<string, (e: KeyboardEvent) => void> = {
      'g o': () => navigateToSection('overview'),
    }

    if (project?.teamId) {
      shortcuts['g p'] = () => navigateToSection('projects')
    }
    if (canShowConnectSection(access, features)) {
      shortcuts['g i'] = () => navigateToSection('apps')
      shortcuts['g k'] = () => navigateToSection('api-keys')
      shortcuts['g x'] = () => navigateToSection('explorer')
    }
    if (canSeeProjectNavItem(access, features, 'databases')) {
      shortcuts['g d'] = () => navigateToSection('databases')
    }
    if (canSeeProjectNavItem(access, features, 'storage')) {
      shortcuts['g s'] = () => navigateToSection('storage')
    }
    if (canSeeProjectNavItem(access, features, 'functions')) {
      shortcuts['g f'] = () => navigateToSection('functions')
    }
    if (canSeeProjectNavItem(access, features, 'messaging')) {
      shortcuts['g m'] = () => navigateToSection('messaging')
    }
    if (canSeeProjectNavItem(access, features, 'sites')) {
      shortcuts['g w'] = () => navigateToSection('sites')
    }
    if (features.activity && canSeeActivityNav(access, features)) {
      shortcuts['g l'] = () => navigateToSection('activity')
    }
    if (features.usageStats && canSeeUsageNav(access, features)) {
      shortcuts['g u'] = () => navigateToSection('usage')
    }
    shortcuts['g a'] = () => navigateToSection('auth')
    if (canShowProjectSettings(access, features)) {
      shortcuts['g e'] = () => navigateToSection('settings')
      shortcuts['g ,'] = () => navigateToSection('settings')
    }

    if (canCreateDatabase(access, features)) {
      shortcuts['c d'] = () => onCreateResource('database')
    }
    if (canCreateBucket(access, features)) {
      shortcuts['c b'] = () => onCreateResource('bucket')
    }
    if (canCreateFunction(access, features)) {
      shortcuts['c f'] = () => onCreateResource('function')
    }
    if (canCreateSite(access, features)) {
      shortcuts['c s'] = () => onCreateResource('site')
    }
    if (canCreateUser(access, features)) {
      shortcuts['c u'] = () => onCreateResource('user')
    }
    if (canCreateTeam(access, features)) {
      shortcuts['c t'] = () => onCreateResource('team')
    }

    return shortcuts
  }, [access, features, navigateToSection, onCreateResource, project?.teamId])

  useSequentialShortcuts(sequentialShortcuts, { enabled: !commandCenterOpen })

  const closeCommandCenter = useCallback(() => {
    setCommandCenterOpen(false)
  }, [])

  const contextValue: KeyboardShortcutsContextValue = {
    openCommandCenter,
    openCommandCenterPage,
    closeCommandCenter,
    isCommandCenterOpen: commandCenterOpen,
  }

  return (
    <KeyboardShortcutsContext.Provider value={contextValue}>
      {children}
      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={handleCommandCenterOpenChange}
        onNavigate={navigateToSection}
        onNavigateToResource={onNavigateToResource}
        onCreateResource={onCreateResource}
        onToggleTerminal={toggleTerminal}
        onOpenConnectMcp={projectConnect ? openConnectMcp : undefined}
        projectId={projectId}
        initialSubPage={initialSubPage}
        onInitialSubPageConsumed={() => setInitialSubPage(null)}
      />
    </KeyboardShortcutsContext.Provider>
  )
}

type StandaloneCommandCenterScopeProps = {
  children: ReactNode
} & Omit<
  ComponentProps<typeof CommandCenter>,
  'open' | 'onOpenChange' | 'initialSubPage' | 'onInitialSubPageConsumed'
>

/** Command center + global shortcuts for pages outside project/org providers (e.g. home). */
export function StandaloneCommandCenterScope({
  children,
  context = 'account',
  ...commandCenterProps
}: StandaloneCommandCenterScopeProps) {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [initialSubPage, setInitialSubPage] = useState<string | null>(null)

  const openCommandCenter = useCallback(() => {
    setInitialSubPage(null)
    setCommandCenterOpen(true)
  }, [])

  const openCommandCenterPage = useCallback((page: CommandCenterPage) => {
    setInitialSubPage(page)
    setCommandCenterOpen(true)
  }, [])

  const openShortcutsHelp = useCallback(() => {
    openCommandCenterPage('shortcuts')
  }, [openCommandCenterPage])

  useEffect(() => {
    return registerCommandCenterOpener((page) => {
      setInitialSubPage(page)
      setCommandCenterOpen(true)
    })
  }, [])

  const closeCommandCenter = useCallback(() => {
    setCommandCenterOpen(false)
    setInitialSubPage(null)
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen,
    onOpenCommandCenter: openCommandCenter,
    onOpenShortcutsHelp: openShortcutsHelp,
  })

  const contextValue = useMemo<KeyboardShortcutsContextValue>(
    () => ({
      openCommandCenter,
      openCommandCenterPage,
      closeCommandCenter,
      isCommandCenterOpen: commandCenterOpen,
    }),
    [
      openCommandCenter,
      openCommandCenterPage,
      closeCommandCenter,
      commandCenterOpen,
    ],
  )

  return (
    <KeyboardShortcutsContext.Provider value={contextValue}>
      {children}
      <CommandCenter
        {...commandCenterProps}
        context={context}
        open={commandCenterOpen}
        onOpenChange={(open) => {
          setCommandCenterOpen(open)
          if (!open) setInitialSubPage(null)
        }}
        initialSubPage={initialSubPage}
        onInitialSubPageConsumed={() => setInitialSubPage(null)}
      />
    </KeyboardShortcutsContext.Provider>
  )
}
