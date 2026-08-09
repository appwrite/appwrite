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
  closeScreenshotMode: () => void
}

const ScreenshotModeContext = createContext<ScreenshotModeContextValue>({
  isScreenshotModeActive: false,
  closeScreenshotMode: () => {},
})

export function useScreenshotMode() {
  return useContext(ScreenshotModeContext)
}

interface ScreenshotModeProviderProps {
  children: ReactNode
}

/**
 * Hide the mode chip during captures. Browsers expose no real OS screenshot
 * event, so we treat any loss of focus/visibility as capture-time (covers
 * menubar tools, CleanShot, system UI, tab switch) and keep keyboard shortcuts
 * only as a fast path for captures that leave the page focused (e.g. Cmd+Shift+3).
 */
function useScreenshotCaptureHidden(enabled: boolean): boolean {
  const [hidden, setHidden] = useState(false)
  const hideUntilRef = useRef(0)
  const releaseTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (!enabled) {
      setHidden(false)
      if (releaseTimerRef.current) {
        clearTimeout(releaseTimerRef.current)
        releaseTimerRef.current = null
      }
      return
    }

    const isPageActive = () =>
      typeof document !== 'undefined' &&
      !document.hidden &&
      document.hasFocus()

    const syncFromPageActivity = () => {
      // While the page is inactive, keep the chip hidden for the whole period
      // (no timer). Timed hides from keyboard are only for focused captures.
      if (!isPageActive()) {
        if (releaseTimerRef.current) {
          clearTimeout(releaseTimerRef.current)
          releaseTimerRef.current = null
        }
        setHidden(true)
        return
      }
      if (Date.now() < hideUntilRef.current) {
        setHidden(true)
        return
      }
      setHidden(false)
    }

    const hideForFocusedCapture = (holdMs: number) => {
      hideUntilRef.current = Date.now() + holdMs
      setHidden(true)
      if (releaseTimerRef.current) clearTimeout(releaseTimerRef.current)
      releaseTimerRef.current = setTimeout(() => {
        syncFromPageActivity()
      }, holdMs)
    }

    const onKeyDown = (e: KeyboardEvent) => {
      // Fast path only: some OS captures never blur the page (e.g. Cmd+Shift+3).
      if (e.code === 'PrintScreen') {
        hideForFocusedCapture(1600)
        return
      }
      if (e.metaKey && e.shiftKey && !e.ctrlKey && !e.altKey) {
        const confirmedDigit =
          e.code === 'Digit3' || e.code === 'Digit4' || e.code === 'Digit5'
        // Digit is often swallowed by the OS; hide as soon as Cmd+Shift is held.
        hideForFocusedCapture(confirmedDigit ? 1600 : 900)
      }
    }

    window.addEventListener('blur', syncFromPageActivity)
    window.addEventListener('focus', syncFromPageActivity)
    document.addEventListener('visibilitychange', syncFromPageActivity)
    // Keyboard remains a supplement, not the primary signal.
    window.addEventListener('keydown', onKeyDown, true)
    syncFromPageActivity()

    return () => {
      window.removeEventListener('blur', syncFromPageActivity)
      window.removeEventListener('focus', syncFromPageActivity)
      document.removeEventListener('visibilitychange', syncFromPageActivity)
      window.removeEventListener('keydown', onKeyDown, true)
      if (releaseTimerRef.current) clearTimeout(releaseTimerRef.current)
    }
  }, [enabled])

  return hidden
}

function ScreenshotModeIndicator({ active }: { active: boolean }) {
  const hiddenForCapture = useScreenshotCaptureHidden(active)
  if (!active || hiddenForCapture) return null

  return (
    <div
      data-screenshot-mode-indicator
      className="pointer-events-none fixed bottom-5 left-1/2 z-[9998] -translate-x-1/2 print:hidden"
      aria-live="polite"
    >
      <div className="flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/15 px-4 py-2 text-[13px] font-semibold text-emerald-700 shadow-md backdrop-blur-sm dark:bg-emerald-500/20 dark:text-emerald-300">
        <span className="size-2 rounded-full bg-emerald-500 shadow-[0_0_0_3px_rgba(16,185,129,0.25)]" aria-hidden />
        Screenshot mode
      </div>
    </div>
  )
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
      value={{ isScreenshotModeActive, closeScreenshotMode }}
    >
      {children}
      <ScreenshotModeIndicator active={isScreenshotModeActive} />
    </ScreenshotModeContext.Provider>
  )
}
