import {
  createContext,
  useContext,
  useState,
  useEffect,
  ReactNode,
} from 'react'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'

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
  const [isDebugModeOpen, setIsDebugModeOpen] = useState(false)

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Toggle visibility when "." is pressed (not in an input field)
      if (e.key === '.' && !shouldSuppressGlobalShortcuts(e.target)) {
        setIsDebugModeOpen((prev) => !prev)
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
