import {
  createContext,
  useContext,
  useState,
  useCallback,
  useEffect,
  type ReactNode,
} from 'react'
import { useNavigate } from '@tanstack/react-router'
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

  // Escape to close
  useKeyboardShortcut(
    'escape',
    () => {
      if (commandCenterOpen) {
        setCommandCenterOpen(false)
      }
    },
    { ignoreInputs: false },
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
      'g l': () => {
        navigateToSection('activity')
      },
      'g u': () => {
        navigateToSection('usage')
      },
      'g ,': () => {
        navigateToSection('settings')
      },

      // Create shortcuts
      'c d': () => {
        navigateToSection('databases')
      },
      'c c': () => {
        navigateToSection('databases')
      },
      'c b': () => {
        navigateToSection('storage')
      },
      'c f': () => {
        navigateToSection('functions')
      },
      'c u': () => {
        navigateToSection('auth')
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
    { enabled: !commandCenterOpen },
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
        projectId={projectId}
      />
    </KeyboardShortcutsContext.Provider>
  )
}
