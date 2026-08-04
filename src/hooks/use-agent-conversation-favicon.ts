import { useEffect, useRef } from 'react'
import { useFavicon, type FaviconVariant } from '@/hooks/use-favicon'
import { getDefaultFaviconVariant } from '@/lib/favicon'
import { usesThemeAwareFaviconHost } from '@/lib/utils/theme-favicon-host'
import {
  getAssistantConversationStatusTone,
  type AssistantConversationStatusTone,
} from '@/lib/assistant/turn-view'

/** How long the success/failure favicon stays before restoring the original. */
const TERMINAL_FAVICON_RESET_MS = 10_000

function inProgressFavicon(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme-blue' : 'blue'
}

function successFavicon(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme-green' : 'green'
}

function failureFavicon(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme-red' : 'red'
}

function isInFlightTone(tone: AssistantConversationStatusTone): boolean {
  return tone === 'running' || tone === 'queued'
}

/** User-stopped / cancelled turns should not flash green (same as build cancel). */
function isCancelledOrStopped(status?: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return (
    normalized === 'stopped' ||
    normalized === 'cancelled' ||
    normalized === 'canceled'
  )
}

/**
 * Swap the tab favicon to match the active agent conversation status while the
 * dedicated `/agent` view is open (blue = in progress, green = success, red =
 * failure). Mirrors the build-notifications favicon convention.
 */
export function useAgentConversationFavicon(
  enabled: boolean,
  conversation?: {
    $id?: string | null
    status?: string | null
    lockState?: string | null
  } | null,
): void {
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const setFaviconRef = useRef(setFavicon)
  setFaviconRef.current = setFavicon
  const getCurrentFaviconRef = useRef(getCurrentFavicon)
  getCurrentFaviconRef.current = getCurrentFavicon

  const originalFaviconRef = useRef<FaviconVariant | null>(null)
  const resetTimerRef = useRef<number | null>(null)
  /** Only conversations we observed in-flight may flash green/red on settle. */
  const trackedInFlightIdRef = useRef<string | null>(null)
  const previousToneRef = useRef<AssistantConversationStatusTone | null>(null)
  const previousConversationIdRef = useRef<string | null>(null)
  const conversationRef = useRef(conversation)
  conversationRef.current = conversation

  const conversationId = conversation?.$id ?? null
  const status = conversation?.status ?? null
  const lockState = conversation?.lockState ?? null

  useEffect(() => {
    if (typeof window === 'undefined') return

    function clearResetTimer(): void {
      if (resetTimerRef.current !== null) {
        window.clearTimeout(resetTimerRef.current)
        resetTimerRef.current = null
      }
    }

    function captureOriginalFavicon(): void {
      if (originalFaviconRef.current) return
      const current = getCurrentFaviconRef.current()
      originalFaviconRef.current = current ?? getDefaultFaviconVariant()
    }

    function restoreOriginalFavicon(): void {
      clearResetTimer()
      const original = originalFaviconRef.current ?? getDefaultFaviconVariant()
      setFaviconRef.current(original)
      originalFaviconRef.current = null
    }

    function applyVariant(variant: FaviconVariant): void {
      clearResetTimer()
      captureOriginalFavicon()
      setFaviconRef.current(variant)
    }

    function scheduleRestore(): void {
      clearResetTimer()
      resetTimerRef.current = window.setTimeout(() => {
        resetTimerRef.current = null
        restoreOriginalFavicon()
      }, TERMINAL_FAVICON_RESET_MS)
    }

    function onVisibilityChange(): void {
      if (document.visibilityState !== 'visible') return
      // Drop terminal green/red once the user is looking at the tab again.
      const currentTone = getAssistantConversationStatusTone(
        conversationRef.current,
      )
      if (
        !isInFlightTone(currentTone) &&
        originalFaviconRef.current &&
        resetTimerRef.current !== null
      ) {
        restoreOriginalFavicon()
      }
    }

    document.addEventListener('visibilitychange', onVisibilityChange)

    if (!enabled) {
      trackedInFlightIdRef.current = null
      previousToneRef.current = null
      previousConversationIdRef.current = null
      if (originalFaviconRef.current) {
        restoreOriginalFavicon()
      }
      return () => {
        document.removeEventListener('visibilitychange', onVisibilityChange)
      }
    }

    const tone = getAssistantConversationStatusTone({ status, lockState })
    const conversationChanged =
      conversationId !== previousConversationIdRef.current

    if (conversationChanged) {
      previousConversationIdRef.current = conversationId
      previousToneRef.current = null
      // Switching threads mid-flash: drop terminal timers but keep the original
      // capture so we can restore when this view closes.
      clearResetTimer()
      if (
        trackedInFlightIdRef.current &&
        trackedInFlightIdRef.current !== conversationId
      ) {
        trackedInFlightIdRef.current = null
      }
    }

    const previousTone = previousToneRef.current
    previousToneRef.current = tone

    if (isInFlightTone(tone)) {
      if (conversationId) {
        trackedInFlightIdRef.current = conversationId
      }
      applyVariant(inProgressFavicon())
    } else {
      const sawInFlight =
        !!conversationId && trackedInFlightIdRef.current === conversationId
      const transitionedFromInFlight =
        previousTone !== null && isInFlightTone(previousTone)

      if (tone === 'failed' && (sawInFlight || transitionedFromInFlight)) {
        trackedInFlightIdRef.current = null
        applyVariant(failureFavicon())
        scheduleRestore()
      } else if (
        tone === 'ready' &&
        (sawInFlight || transitionedFromInFlight) &&
        previousTone !== null
      ) {
        trackedInFlightIdRef.current = null
        if (isCancelledOrStopped(status)) {
          // Cancel is a user action, not success — restore immediately.
          restoreOriginalFavicon()
        } else {
          applyVariant(successFavicon())
          scheduleRestore()
        }
      } else if (originalFaviconRef.current && !resetTimerRef.current) {
        // Idle without an observed in-flight turn: keep default.
        restoreOriginalFavicon()
      }
    }

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }, [enabled, conversationId, status, lockState])

  useEffect(() => {
    return () => {
      if (typeof window === 'undefined') return
      if (resetTimerRef.current !== null) {
        window.clearTimeout(resetTimerRef.current)
        resetTimerRef.current = null
      }
      if (originalFaviconRef.current) {
        setFaviconRef.current(
          originalFaviconRef.current ?? getDefaultFaviconVariant(),
        )
        originalFaviconRef.current = null
      }
    }
  }, [])
}
