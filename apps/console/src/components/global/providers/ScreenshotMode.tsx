import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'
import {
  readScreenshotModeOpen,
  SCREENSHOT_MODE_TOGGLE_SEQUENCE,
  subscribeScreenshotMode,
  writeScreenshotModeOpen,
} from '@/lib/screenshot-mode'

interface ScreenshotModeContextValue {
  isScreenshotModeActive: boolean
  setScreenshotModeActive: (open: boolean) => void
  closeScreenshotMode: () => void
}

const ScreenshotModeContext = createContext<ScreenshotModeContextValue>({
  isScreenshotModeActive: false,
  setScreenshotModeActive: () => {},
  closeScreenshotMode: () => {},
})

export function useScreenshotMode() {
  return useContext(ScreenshotModeContext)
}

interface ScreenshotModeProviderProps {
  children: ReactNode
}

function invalidateScreenshotModeQueries(
  queryClient: ReturnType<typeof useQueryClient>,
) {
  // Chart series are mocked inside usage fetch helpers - refetch so mocks apply/clear.
  void queryClient.invalidateQueries({ queryKey: ['usage-events'] })
  void queryClient.invalidateQueries({ queryKey: ['usage-gauges'] })
  void queryClient.invalidateQueries({ queryKey: ['usage-breakdown'] })
}

export function ScreenshotModeProvider({ children }: ScreenshotModeProviderProps) {
  const queryClient = useQueryClient()
  const [isScreenshotModeActive, setIsScreenshotModeActive] = useState(() =>
    readScreenshotModeOpen(),
  )
  const typedSequenceRef = useRef('')
  const skipPersistEffectRef = useRef(true)

  const closeScreenshotMode = useCallback(() => {
    setIsScreenshotModeActive(false)
  }, [])

  const setScreenshotModeActive = useCallback((open: boolean) => {
    setIsScreenshotModeActive(open)
  }, [])

  // Persist + notify subscribers + refresh charts after React finishes the state update.
  // Never do this inside a setState updater - writeScreenshotModeOpen notifies RootDocument
  // (via useAuth) and would update a parent while this provider is still rendering.
  useEffect(() => {
    if (skipPersistEffectRef.current) {
      skipPersistEffectRef.current = false
      return
    }
    writeScreenshotModeOpen(isScreenshotModeActive)
    invalidateScreenshotModeQueries(queryClient)
  }, [isScreenshotModeActive, queryClient])

  useEffect(() => {
    return subscribeScreenshotMode((open) => {
      setIsScreenshotModeActive((prev) => (prev === open ? prev : open))
    })
  }, [])

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (
        shouldSuppressGlobalShortcuts(e.target) ||
        e.metaKey ||
        e.ctrlKey ||
        e.altKey
      ) {
        return
      }

      if (e.key.length !== 1) {
        typedSequenceRef.current = ''
        return
      }

      typedSequenceRef.current = (
        typedSequenceRef.current + e.key
      ).slice(-SCREENSHOT_MODE_TOGGLE_SEQUENCE.length)

      if (
        typedSequenceRef.current.toLowerCase() ===
        SCREENSHOT_MODE_TOGGLE_SEQUENCE
      ) {
        typedSequenceRef.current = ''
        setIsScreenshotModeActive((prev) => !prev)
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [])

  return (
    <ScreenshotModeContext.Provider
      value={{
        isScreenshotModeActive,
        setScreenshotModeActive,
        closeScreenshotMode,
      }}
    >
      {children}
    </ScreenshotModeContext.Provider>
  )
}
