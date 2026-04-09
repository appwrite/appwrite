import {
  createContext,
  useContext,
  useState,
  useCallback,
  type ReactNode,
} from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useKeyboardShortcut,
  useSequentialShortcuts,
} from '@/hooks/use-keyboard-shortcuts'
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
  const navigate = useNavigate()
  const { features } = useConsoleProfile()

  const navigateToSection = useCallback(
    (section: string) => {
      if (section === 'overview') {
        navigate({ to: `/projects/${projectId}` })
      } else {
        navigate({ to: `/projects/${projectId}/${section}` })
      }
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
          to: '/projects/$projectId/storage',
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

  // Command Center shortcut (Cmd+K / Ctrl+K)
  useKeyboardShortcut('meta+k', () => {
    setCommandCenterOpen(true)
  })

  useKeyboardShortcut('control+k', () => {
    setCommandCenterOpen(true)
  })

  // Focus search shortcut (/)
  useKeyboardShortcut('/', (e) => {
    e.preventDefault()
    if (onFocusSearch) {
      onFocusSearch()
    } else {
      setCommandCenterOpen(true)
    }
  })

  // Show shortcuts help (?)
  useKeyboardShortcut('shift+/', () => {
    setCommandCenterOpen(true)
    // The command center will show shortcuts when ? is pressed
  })

  // Escape to close (ignore when typing so editors e.g. Monaco keep Escape)
  useKeyboardShortcut(
    'escape',
    () => {
      if (commandCenterOpen) {
        setCommandCenterOpen(false)
      }
    },
    { ignoreInputs: true },
  )

  // Sequential shortcuts for navigation (vim-style)
  useSequentialShortcuts(
    {
      // Go to... shortcuts
      'g o': () => {
        navigateToSection('overview')
      },
      'g d': () => {
        navigateToSection('databases')
      },
      'g a': () => {
        navigateToSection('auth')
      },
      'g s': () => {
        navigateToSection('storage')
      },
      'g f': () => {
        navigateToSection('functions')
      },
      'g m': () => {
        navigateToSection('messaging')
      },
      'g i': () => {
        navigateToSection('apps')
      },
      'g k': () => {
        navigateToSection('api-keys')
      },
      'g w': () => {
        navigateToSection('sites')
      },
      ...(features.activity
        ? {
            'g l': () => {
              navigateToSection('activity')
            },
          }
        : {}),
      ...(features.usageStats
        ? {
            'g u': () => {
              navigateToSection('usage')
            },
          }
        : {}),
      'g ,': () => {
        navigateToSection('settings')
      },

      // Create shortcuts (open modal/wizard)
      'c d': () => {
        onCreateResource('database')
      },
      'c b': () => {
        onCreateResource('bucket')
      },
      'c f': () => {
        onCreateResource('function')
      },
      'c s': () => {
        onCreateResource('site')
      },
      'c u': () => {
        onCreateResource('user')
      },
      'c t': () => {
        onCreateResource('team')
      },
    },
    { enabled: !commandCenterOpen },
  )

  // Single key shortcuts
  useKeyboardShortcut(
    'l',
    () => {
      navigateToSection('activity')
    },
    { enabled: !commandCenterOpen && features.activity },
  )

  const openCommandCenter = useCallback(() => {
    setCommandCenterOpen(true)
  }, [])

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
        onOpenChange={setCommandCenterOpen}
        onNavigate={navigateToSection}
        onNavigateToResource={onNavigateToResource}
        onCreateResource={onCreateResource}
        projectId={projectId}
      />
    </KeyboardShortcutsContext.Provider>
  )
}
