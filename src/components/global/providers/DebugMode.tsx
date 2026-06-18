import {
  createContext,
  useContext,
  useState,
  useEffect,
  ReactNode,
} from 'react'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'

const DEBUG_MODE_OPEN_KEY = 'debug:modeOpen'

function readDebugModeOpen(): boolean {
  if (typeof window === 'undefined') return false
  try {
    return localStorage.getItem(DEBUG_MODE_OPEN_KEY) === 'true'
  } catch {
    return false
  }
}

function writeDebugModeOpen(open: boolean): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem(DEBUG_MODE_OPEN_KEY, open ? 'true' : 'false')
  } catch {
    // localStorage unavailable
  }
}

interface DebugModeContextValue {
  isDebugModeOpen: boolean
}

const DebugModeContext = createContext<DebugModeContextValue>({
  isDebugModeOpen: false,
})

export function useDebugMode() {
  return useContext(DebugModeContext)
}

interface DebugModeProviderProps {
  children: ReactNode
}

export function DebugModeProvider({ children }: DebugModeProviderProps) {
  const [isDebugModeOpen, setIsDebugModeOpen] = useState(() =>
    readDebugModeOpen(),
  )

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Toggle visibility when "." is pressed (not in an input field)
      if (e.key === '.' && !shouldSuppressGlobalShortcuts(e.target)) {
        setIsDebugModeOpen((prev) => {
          const next = !prev
          writeDebugModeOpen(next)
          return next
        })
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [])

  return (
    <DebugModeContext.Provider value={{ isDebugModeOpen }}>
      {children}
    </DebugModeContext.Provider>
  )
}
