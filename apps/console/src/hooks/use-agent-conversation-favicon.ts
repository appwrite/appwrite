import { useEffect, useRef } from 'react'
import {
  useFavicon,
  type FaviconApplyMeta,
  type FaviconVariant,
} from '@/hooks/use-favicon'
import {
  getDefaultFaviconVariant,
  isStatusFaviconVariant,
  type FaviconStatusContext,
} from '@/lib/favicon'
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
  projectId?: string | null
  projectName?: string | null
  organizationId?: string | null
  pathname?: string | null
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
  projectId = null,
  projectName = null,
  organizationId = null,
  pathname = null,
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
  const projectIdRef = useRef(projectId)
  projectIdRef.current = projectId
  const projectNameRef = useRef(projectName)
  projectNameRef.current = projectName
  const organizationIdRef = useRef(organizationId)
  organizationIdRef.current = organizationId
  const pathnameRef = useRef(pathname)
  pathnameRef.current = pathname

  const conversationId = conversation?.$id ?? null
  const status = conversation?.status ?? null
  const lockState = conversation?.lockState ?? null

  useEffect(() => {
    if (typeof window === 'undefined') return

    function buildContext(
      conversationIdValue: string | null,
      statusValue: string | null,
      lockStateValue: string | null,
      pending: boolean,
    ): FaviconStatusContext {
      const fields: NonNullable<FaviconStatusContext['fields']> = []
      if (statusValue) fields.push({ label: 'Status', value: statusValue })
      if (lockStateValue) fields.push({ label: 'Lock', value: lockStateValue })
      if (pending) fields.push({ label: 'Pending', value: 'message create/update' })
      return {
        projectId: projectIdRef.current ?? undefined,
        projectName: projectNameRef.current ?? undefined,
        organizationId: organizationIdRef.current ?? undefined,
        conversationId: conversationIdValue ?? undefined,
        pathname: pathnameRef.current ?? undefined,
        fields: fields.length > 0 ? fields : undefined,
      }
    }

    function agentMeta(
      reason: string,
      conversationIdValue: string | null,
      statusValue: string | null,
      lockStateValue: string | null,
      pending: boolean,
    ): FaviconApplyMeta {
      const context = buildContext(
        conversationIdValue,
        statusValue,
        lockStateValue,
        pending,
      )
      const detailParts = [
        context.projectId ? `project ${context.projectId}` : null,
        context.conversationId ? `conversation ${context.conversationId}` : null,
        statusValue ? `status=${statusValue}` : null,
        pending ? 'message pending' : null,
      ].filter(Boolean)
      return {
        source: 'agent-conversation',
        reason,
        detail: detailParts.length > 0 ? detailParts.join(' · ') : undefined,
        context,
      }
    }

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
      originalFaviconRef.current = isStatusFaviconVariant(resolved)
        ? getDefaultFaviconVariant()
        : resolved
    }

    function restoreOriginalFavicon(reason: string): void {
      clearResetTimer()
      const original = originalFaviconRef.current ?? getDefaultFaviconVariant()
      setFaviconRef.current(
        original,
        agentMeta(reason, conversationId, status, lockState, isPending),
      )
      originalFaviconRef.current = null
    }

    function applyVariant(variant: FaviconVariant, reason: string): void {
      clearResetTimer()
      captureOriginalFavicon()
      setFaviconRef.current(
        variant,
        agentMeta(reason, conversationId, status, lockState, isPending),
      )
    }

    function scheduleRestore(): void {
      clearResetTimer()
      resetTimerRef.current = window.setTimeout(() => {
        resetTimerRef.current = null
        restoreOriginalFavicon('Restored after agent terminal favicon timeout')
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
        restoreOriginalFavicon(
          'Restored when tab became visible after agent settled',
        )
      }
    }

    document.addEventListener('visibilitychange', onVisibilityChange)

    if (!enabled) {
      trackedInFlightIdRef.current = null
      previousToneRef.current = null
      previousConversationIdRef.current = null
      if (originalFaviconRef.current) {
        restoreOriginalFavicon(
          'Restored because agent favicon tracking disabled',
        )
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
        trackedInFlightIdRef.current =
          trackedInFlightIdRef.current ?? '__pending__'
      }
      const reason =
        isPending && !isInFlightTone(tone)
          ? 'Agent message pending (waiting for conversation status)'
          : tone === 'queued'
            ? 'Agent conversation queued'
            : 'Agent conversation running'
      applyVariant(inProgressFavicon(), reason)
    } else {
      const sawInFlight =
        !!conversationId &&
        (trackedInFlightIdRef.current === conversationId ||
          trackedInFlightIdRef.current === '__pending__')
      const transitionedFromInFlight =
        previousTone !== null && isInFlightTone(previousTone)

      if (tone === 'failed' && (sawInFlight || transitionedFromInFlight)) {
        trackedInFlightIdRef.current = null
        applyVariant(failureFavicon(), 'Agent conversation failed')
        scheduleRestore()
      } else if (
        tone === 'ready' &&
        (sawInFlight || transitionedFromInFlight) &&
        previousTone !== null
      ) {
        trackedInFlightIdRef.current = null
        if (isCancelledOrStopped(status)) {
          restoreOriginalFavicon(
            'Restored after agent conversation was stopped',
          )
        } else {
          applyVariant(successFavicon(), 'Agent conversation ready')
          scheduleRestore()
        }
      } else if (originalFaviconRef.current && !resetTimerRef.current) {
        restoreOriginalFavicon(
          'Restored after agent conversation left in-flight',
        )
      }
    }

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }, [
    enabled,
    conversationId,
    status,
    lockState,
    isPending,
    projectId,
    projectName,
    organizationId,
    pathname,
  ])

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
          {
            source: 'agent-conversation',
            reason: 'Restored on agent favicon hook unmount',
            context: {
              projectId: projectIdRef.current ?? undefined,
              projectName: projectNameRef.current ?? undefined,
              organizationId: organizationIdRef.current ?? undefined,
              pathname: pathnameRef.current ?? undefined,
            },
          },
        )
        originalFaviconRef.current = null
      }
    }
  }, [])
}
