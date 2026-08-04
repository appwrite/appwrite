import {
  createContext,
  useCallback,
  useContext,
  useState,
  useEffect,
  useRef,
  ReactNode,
} from 'react'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'

const DEBUG_MODE_OPEN_KEY = 'debug:modeOpen'
/** Case-insensitive key sequence that toggles the debug menu. */
const DEBUG_MODE_TOGGLE_SEQUENCE = 'pink'

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
  closeDebugMode: () => void
}

const DebugModeContext = createContext<DebugModeContextValue>({
  isDebugModeOpen: false,
  closeDebugMode: () => {},
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
  const typedSequenceRef = useRef('')

  const closeDebugMode = useCallback(() => {
    setIsDebugModeOpen(false)
    writeDebugModeOpen(false)
  }, [])

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Ignore when typing in inputs, or with modifier keys held
      if (
        shouldSuppressGlobalShortcuts(e.target) ||
        e.metaKey ||
        e.ctrlKey ||
        e.altKey
      ) {
        return
      }

      // Only track printable single-character keys
      if (e.key.length !== 1) {
        typedSequenceRef.current = ''
        return
      }

      typedSequenceRef.current = (
        typedSequenceRef.current + e.key
      ).slice(-DEBUG_MODE_TOGGLE_SEQUENCE.length)

      if (
        typedSequenceRef.current.toLowerCase() === DEBUG_MODE_TOGGLE_SEQUENCE
      ) {
        typedSequenceRef.current = ''
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
    <DebugModeContext.Provider value={{ isDebugModeOpen, closeDebugMode }}>
      {children}
    </DebugModeContext.Provider>
  )
}
