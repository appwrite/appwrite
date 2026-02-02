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
import { cn } from '@/lib/utils'
import { motion, AnimatePresence } from 'motion/react'

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
      {/* Show keyboard indicator when not in command center */}
      {!commandCenterOpen && <KeyboardIndicator />}
    </KeyboardShortcutsContext.Provider>
  )
}

export function KeyboardIndicator({ className }: { className?: string }) {
  const [keys, setKeys] = useState<string[]>([])
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    let timeout: NodeJS.Timeout | null = null

    const handleKeyDown = (e: KeyboardEvent) => {
      // Ignore if in input
      const target = e.target as Element
      if (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.getAttribute('contenteditable') === 'true'
      ) {
        return
      }

      // Ignore modifier-only keys
      if (['Meta', 'Control', 'Alt', 'Shift'].includes(e.key)) {
        return
      }

      // Ignore if any modifier is pressed (except shift)
      if (e.metaKey || e.ctrlKey || e.altKey) {
        return
      }

      // Show the indicator
      setVisible(true)
      setKeys((prev) => [...prev, e.key.toUpperCase()])

      // Clear timeout
      if (timeout) {
        clearTimeout(timeout)
      }

      // Hide after delay
      timeout = setTimeout(() => {
        setVisible(false)
        setKeys([])
      }, 1500)
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      if (timeout) clearTimeout(timeout)
    }
  }, [])

  return (
    <AnimatePresence>
      {visible && keys.length > 0 && (
        <motion.div
          initial={{ opacity: 0, y: 10, scale: 0.95 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: -10, scale: 0.95 }}
          transition={{ duration: 0.15 }}
          className={cn(
            'fixed bottom-6 left-1/2 z-50 -translate-x-1/2',
            className,
          )}
        >
          <div className="flex items-center gap-1.5 rounded-lg border border-border bg-popover/95 px-3 py-2 shadow-xl backdrop-blur-sm">
            {keys.map((key, i) => (
              <span key={i} className="flex items-center gap-1.5">
                {i > 0 && <span className="text-muted-foreground/30">+</span>}
                <kbd className="flex h-7 min-w-[28px] items-center justify-center rounded-md bg-accent px-2 text-[13px] font-medium text-foreground shadow-sm">
                  {key === ' ' ? '␣' : key}
                </kbd>
              </span>
            ))}
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
