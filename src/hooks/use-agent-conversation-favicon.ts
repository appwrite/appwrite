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

type AgentConversationFaviconInput = {
  /** When false, restore the original favicon and stop tracking. */
  enabled?: boolean
  conversation?: {
    $id?: string | null
    status?: string | null
    lockState?: string | null
  } | null
  /**
   * Local busy signal (e.g. message create pending) so the favicon flips blue
   * before conversation status catches up from realtime.
   */
  isPending?: boolean
}

/**
 * Swap the tab favicon to match the active agent conversation status (blue =
 * in progress, green = success, red = failure). Mirrors the build-notifications
 * favicon convention. Works for both the `/agent` page and the console pane.
 */
export function useAgentConversationFavicon({
  enabled = true,
  conversation,
  isPending = false,
}: AgentConversationFaviconInput): void {
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
  const isPendingRef = useRef(isPending)
  isPendingRef.current = isPending

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
      // Never capture a status color as the "original" — otherwise restore
      // leaves the tab stuck on blue/green/red.
      const resolved = current ?? getDefaultFaviconVariant()
      originalFaviconRef.current =
        resolved === 'blue' ||
        resolved === 'green' ||
        resolved === 'red' ||
        resolved === 'theme-blue' ||
        resolved === 'theme-green' ||
        resolved === 'theme-red'
          ? getDefaultFaviconVariant()
          : resolved
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
      const currentTone = getAssistantConversationStatusTone(
        conversationRef.current,
      )
      if (
        !isInFlightTone(currentTone) &&
        !isPendingRef.current &&
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
    const inFlight = isInFlightTone(tone) || isPending

    if (inFlight) {
      if (conversationId) {
        trackedInFlightIdRef.current = conversationId
      } else if (isPending) {
        // Brand-new send before the conversation id is known.
        trackedInFlightIdRef.current = trackedInFlightIdRef.current ?? '__pending__'
      }
      applyVariant(inProgressFavicon())
    } else {
      const sawInFlight =
        !!conversationId &&
        (trackedInFlightIdRef.current === conversationId ||
          trackedInFlightIdRef.current === '__pending__')
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
          restoreOriginalFavicon()
        } else {
          applyVariant(successFavicon())
          scheduleRestore()
        }
      } else if (originalFaviconRef.current && !resetTimerRef.current) {
        restoreOriginalFavicon()
      }
    }

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }, [enabled, conversationId, status, lockState, isPending])

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
