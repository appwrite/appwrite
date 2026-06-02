import {
  createContext,
  useContext,
  useState,
  useCallback,
  useMemo,
  type ReactNode,
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

interface KeyboardShortcutsContextValue {
  openCommandCenter: () => void
  closeCommandCenter: () => void
  isCommandCenterOpen: boolean
}

const KeyboardShortcutsContext =
  createContext<KeyboardShortcutsContextValue | null>(null)

// Default no-op context for when used outside provider (e.g., on org overview page)
const defaultContextValue: KeyboardShortcutsContextValue = {
  openCommandCenter: () => {},
  closeCommandCenter: () => {},
  isCommandCenterOpen: false,
}

export function useKeyboardShortcutsContext() {
  const context = useContext(KeyboardShortcutsContext)
  // Return default context if not within provider (e.g., on org overview page)
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
    [navigate, projectId],
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

  const openShortcutsHelp = useCallback(() => {
    setInitialSubPage('shortcuts')
    setCommandCenterOpen(true)
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

    if (canShowConnectSection(access, features)) {
      shortcuts['g i'] = () => navigateToSection('apps')
      shortcuts['g k'] = () => navigateToSection('api-keys')
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
  }, [access, features, navigateToSection, onCreateResource])

  useSequentialShortcuts(sequentialShortcuts, { enabled: !commandCenterOpen })

  const closeCommandCenter = useCallback(() => {
    setCommandCenterOpen(false)
  }, [])

  const contextValue: KeyboardShortcutsContextValue = {
    openCommandCenter,
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
        projectId={projectId}
        initialSubPage={initialSubPage}
        onInitialSubPageConsumed={() => setInitialSubPage(null)}
      />
    </KeyboardShortcutsContext.Provider>
  )
}
