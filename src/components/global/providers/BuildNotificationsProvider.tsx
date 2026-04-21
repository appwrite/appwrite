/**
 * BuildNotificationsProvider
 *
 * Surfaces ongoing site / function deployments outside the active tab:
 *
 * - Swaps the favicon to the orange (in-progress) variant while one or more builds
 *   are still running, so users can see something is happening even when this tab
 *   is in the background.
 * - When all tracked builds finish, briefly shows the green (success) or red
 *   (failure) favicon variant, then resets to the original favicon either after a
 *   short timeout or as soon as the user comes back to this tab.
 * - Sends a desktop Notification when a build completes (only while the tab is
 *   hidden, so we don't spam users who can already see the UI). We also try to
 *   prompt for permission on first user gesture, since most browsers block the
 *   prompt outside of one.
 *
 * It hooks into the same realtime channel the rest of the app already uses, so it
 * doesn't open additional WebSockets.
 */

import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { RealtimeResponseEvent, Models } from '@appwrite.io/console'
import { useFavicon, type FaviconVariant } from '@/hooks/use-favicon'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'
import { registerRegionalConsoleRealtimeListener } from '@/lib/realtime/regional-console-hub'
import { PROJECT_CHANNELS } from '@/lib/realtime/constants'

type DeploymentResource = 'site' | 'function'

interface ActiveBuild {
  deploymentId: string
  resourceId: string
  resourceType: DeploymentResource
  status: string
}

/** How long the success/failure favicon stays before we restore the original. */
const TERMINAL_FAVICON_RESET_MS = 10_000

function isInProgressStatus(status: string): boolean {
  return (
    status === 'building' || status === 'processing' || status === 'waiting'
  )
}

function isTerminalStatus(status: string): boolean {
  return (
    status === 'ready' ||
    status === 'failed' ||
    status === 'canceled' ||
    status === 'cancelled'
  )
}

function isFailureStatus(status: string): boolean {
  return (
    status === 'failed' || status === 'canceled' || status === 'cancelled'
  )
}

let notificationPermissionRequested = false

/**
 * Ask for permission. Safe to call from any context: outside a user gesture
 * most browsers will silently reject the prompt, but we attach this to a
 * `pointerdown` listener too so the next click triggers the real prompt.
 */
function requestNotificationPermissionOnce(): void {
  if (notificationPermissionRequested) return
  if (typeof window === 'undefined') return
  if (typeof Notification === 'undefined') return
  if (Notification.permission !== 'default') {
    notificationPermissionRequested = true
    return
  }
  notificationPermissionRequested = true
  try {
    const result = Notification.requestPermission()
    if (result && typeof (result as Promise<unknown>).catch === 'function') {
      void (result as Promise<unknown>).catch(() => {
        // Browser rejected (e.g. no user gesture); the gesture listener will retry.
        notificationPermissionRequested = false
      })
    }
  } catch {
    notificationPermissionRequested = false
  }
}

/**
 * Some browsers (Safari, Firefox, recent Chrome) require a user gesture before
 * showing the permission prompt. Hooking a one-time capture-phase listener to
 * `pointerdown` lets the next click anywhere on the page (typically the user's
 * own Deploy button) be the gesture that prompts them.
 */
function ensurePermissionPromptOnNextGesture(): () => void {
  if (typeof window === 'undefined') return () => {}
  if (typeof Notification === 'undefined') return () => {}
  if (Notification.permission !== 'default') return () => {}

  const onGesture = () => {
    notificationPermissionRequested = false
    requestNotificationPermissionOnce()
    cleanup()
  }
  const cleanup = () => {
    document.removeEventListener('pointerdown', onGesture, true)
    document.removeEventListener('keydown', onGesture, true)
  }
  document.addEventListener('pointerdown', onGesture, true)
  document.addEventListener('keydown', onGesture, true)
  return cleanup
}

interface BuildNotificationsProviderProps {
  projectId: string
}

export function BuildNotificationsProvider({
  projectId,
}: BuildNotificationsProviderProps) {
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const queryClient = useQueryClient()

  // Stable refs so the realtime handler always sees the latest state.
  const activeBuildsRef = useRef<Map<string, ActiveBuild>>(new Map())
  /**
   * Deployment IDs we've already fired a terminal notification for. Prevents
   * duplicate notifications when realtime emits multiple update events with
   * the same final status.
   */
  const notifiedTerminalRef = useRef<Set<string>>(new Set())
  const resetTimerRef = useRef<number | null>(null)
  const originalFaviconRef = useRef<FaviconVariant | null>(null)
  const projectIdRef = useRef(projectId)
  projectIdRef.current = projectId
  const queryClientRef = useRef(queryClient)
  queryClientRef.current = queryClient
  const setFaviconRef = useRef(setFavicon)
  setFaviconRef.current = setFavicon
  const getCurrentFaviconRef = useRef(getCurrentFavicon)
  getCurrentFaviconRef.current = getCurrentFavicon

  useEffect(() => {
    if (typeof window === 'undefined') return

    // Try right away (works on browsers that don't require gestures) and
    // arm a one-time gesture listener for those that do.
    requestNotificationPermissionOnce()
    const cleanupPermissionGesture = ensurePermissionPromptOnNextGesture()

    function captureOriginalFavicon(): void {
      if (originalFaviconRef.current) return
      const current = getCurrentFaviconRef.current()
      originalFaviconRef.current = current ?? 'default'
    }

    function clearResetTimer(): void {
      if (resetTimerRef.current !== null) {
        window.clearTimeout(resetTimerRef.current)
        resetTimerRef.current = null
      }
    }

    function restoreOriginalFavicon(): void {
      const original = originalFaviconRef.current ?? 'default'
      setFaviconRef.current(original)
    }

    function scheduleFaviconReset(): void {
      clearResetTimer()
      resetTimerRef.current = window.setTimeout(() => {
        resetTimerRef.current = null
        if (activeBuildsRef.current.size === 0) {
          restoreOriginalFavicon()
        }
      }, TERMINAL_FAVICON_RESET_MS)
    }

    function lookupResourceName(
      resourceType: DeploymentResource,
      resourceId: string,
    ): string {
      const key =
        resourceType === 'site'
          ? ['site', 'project', projectIdRef.current, resourceId]
          : ['function', 'project', projectIdRef.current, resourceId]
      const cached = queryClientRef.current.getQueryData<
        Models.Site | Models.Function | undefined
      >(key)
      const name = (cached as { name?: string } | undefined)?.name
      return name && name.trim().length > 0 ? name : resourceId
    }

    function notify(title: string, body: string, tag: string): void {
      if (typeof Notification === 'undefined') return
      if (Notification.permission !== 'granted') return
      // Skip when the tab is already in front - the in-app UI is enough.
      if (document.visibilityState === 'visible') return
      try {
        new Notification(title, {
          body,
          icon: '/logo.svg',
          tag,
        })
      } catch {
        // Some browsers throw when constructing a Notification fails (e.g. Safari);
        // there's nothing actionable we can do, so swallow it.
      }
    }

    function handleStatusChange(update: {
      deploymentId: string
      resourceId: string
      resourceType: DeploymentResource
      status: string
    }): void {
      const builds = activeBuildsRef.current

      if (isInProgressStatus(update.status)) {
        // Re-arm dedupe if the same deployment ID restarts (rare, but harmless).
        notifiedTerminalRef.current.delete(update.deploymentId)
        builds.set(update.deploymentId, { ...update })
        captureOriginalFavicon()
        clearResetTimer()
        setFaviconRef.current('theme-orange')
        // Fire the permission request lazily here too; the page-level pointerdown
        // listener will catch the actual user gesture.
        requestNotificationPermissionOnce()
        return
      }

      if (!isTerminalStatus(update.status)) return
      // Dedupe so multiple realtime updates for the same final status only
      // produce one notification.
      if (notifiedTerminalRef.current.has(update.deploymentId)) {
        builds.delete(update.deploymentId)
        return
      }
      notifiedTerminalRef.current.add(update.deploymentId)
      builds.delete(update.deploymentId)

      const failed = isFailureStatus(update.status)
      const canceled =
        update.status === 'canceled' || update.status === 'cancelled'
      const name = lookupResourceName(update.resourceType, update.resourceId)
      const label = update.resourceType === 'site' ? 'Site' : 'Function'
      const verb =
        update.status === 'ready'
          ? 'completed successfully'
          : update.status === 'failed'
            ? 'failed'
            : 'was canceled'
      notify(
        `${label} build ${update.status === 'ready' ? 'ready' : update.status === 'failed' ? 'failed' : 'canceled'}`,
        `${name} build ${verb}.`,
        `appwrite-build-${update.deploymentId}`,
      )

      if (builds.size === 0) {
        captureOriginalFavicon()
        if (canceled) {
          // Canceling is a user action, not an error - just go back to normal.
          restoreOriginalFavicon()
        } else {
          setFaviconRef.current(failed ? 'theme-red' : 'theme-green')
          scheduleFaviconReset()
        }
      } else {
        // Other builds still running - keep the in-progress favicon.
        setFaviconRef.current('theme-orange')
      }
    }

    function handleRealtimeEvent(
      event: RealtimeResponseEvent<unknown>,
    ): void {
      const { events } = event
      const isSite = events.some(
        (name) => name.startsWith('sites.') && name.includes('.deployments.'),
      )
      const isFunction = events.some(
        (name) =>
          name.startsWith('functions.') && name.includes('.deployments.'),
      )
      if (!isSite && !isFunction) return

      const payload =
        event.payload && typeof event.payload === 'object'
          ? (event.payload as Record<string, unknown>)
          : null
      if (!payload) return

      const deploymentId = payload.$id as string | undefined
      const status = payload.status as string | undefined
      if (!deploymentId || !status) return

      const resourceId =
        (payload.resourceId as string | undefined) ?? ''
      if (!resourceId) return

      const resourceTypeRaw =
        (payload.resourceType as string | undefined) ??
        (isSite ? 'site' : 'function')
      const resourceType: DeploymentResource =
        resourceTypeRaw === 'function' ? 'function' : 'site'

      handleStatusChange({
        deploymentId,
        resourceId,
        resourceType,
        status,
      })
    }

    function onVisibilityChange(): void {
      if (document.visibilityState !== 'visible') return
      // User is looking at us again - drop any "completed" indicator immediately
      // so the favicon doesn't keep nagging once they've seen the result.
      if (activeBuildsRef.current.size === 0 && originalFaviconRef.current) {
        clearResetTimer()
        restoreOriginalFavicon()
      }
    }

    document.addEventListener('visibilitychange', onVisibilityChange)

    let cancelled = false
    let unregisterMain: (() => Promise<void>) | null = null
    let unregisterRegional: (() => Promise<void>) | null = null

    void (async () => {
      const channels = [...PROJECT_CHANNELS]
      const main = await registerConsoleRealtimeListener(
        channels,
        handleRealtimeEvent,
      )
      const regional = await registerRegionalConsoleRealtimeListener(
        projectId,
        channels,
        handleRealtimeEvent,
      )
      if (cancelled) {
        await Promise.all([main(), regional()])
        return
      }
      unregisterMain = main
      unregisterRegional = regional
    })()

    return () => {
      cancelled = true
      document.removeEventListener('visibilitychange', onVisibilityChange)
      cleanupPermissionGesture()
      clearResetTimer()
      // If we tweaked the favicon, make sure we leave it as the user found it.
      if (originalFaviconRef.current) {
        restoreOriginalFavicon()
      }
      activeBuildsRef.current.clear()
      notifiedTerminalRef.current.clear()
      originalFaviconRef.current = null
      void Promise.all([
        unregisterMain ? unregisterMain() : Promise.resolve(),
        unregisterRegional ? unregisterRegional() : Promise.resolve(),
      ])
    }
  }, [projectId])

  return null
}
