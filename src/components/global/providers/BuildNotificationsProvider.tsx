/**
 * BuildNotificationsProvider
 *
 * Surfaces ongoing site / function deployments outside the active tab:
 *
 * - Swaps the favicon to the orange (in-progress) variant while one or more builds
 *   are still running, so users can see something is happening even when this tab
 *   is in the background. Favicon changes only happen when the user is on a
 *   build-relevant route (Sites or Functions); on other sections of the project
 *   the favicon is left untouched so unrelated work isn't visually disrupted.
 * - When all tracked builds finish, briefly shows the green (success) or red
 *   (failure) favicon variant, then resets to the original favicon either after a
 *   short timeout or as soon as the user comes back to this tab. Only deployments
 *   we observed transition through their in-progress phase are eligible to
 *   trigger a success/failure favicon - this avoids stray red flashes from
 *   unrelated terminal events for other resources in the project.
 * - Sends a desktop Notification when a build completes (only when the page
 *   does not have focus - i.e. the user is on another tab or in another app -
 *   so we don't spam users who can already see the UI). Permission is
 *   requested via an in-app toast button so the request happens inside a real
 *   user gesture, which most browsers require.
 *
 * It hooks into the same realtime channel the rest of the app already uses, so it
 * doesn't open additional WebSockets.
 */

import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useLocation } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Bell } from 'lucide-react'
import type { RealtimeResponseEvent, Models } from '@appwrite.io/console'
import { useFavicon, type FaviconVariant } from '@/hooks/use-favicon'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'
import { registerRegionalConsoleRealtimeListener } from '@/lib/realtime/regional-console-hub'
import { PROJECT_CHANNELS } from '@/lib/realtime/constants'

/** localStorage key for remembering that the user explicitly opted out of the prompt. */
const OPT_OUT_STORAGE_KEY = 'appwrite.buildNotifications.optedOut'

interface ActiveBuild {
  deploymentId: string
  resourceId: string
  resourceType: DeploymentResource
  status: string
}

/** How long the success/failure favicon stays before we restore the original. */
const TERMINAL_FAVICON_RESET_MS = 10_000

/**
 * What the user is currently looking at, used to decide whether a deployment
 * event should affect the favicon / surface a permission prompt. We want to be
 * strict here so the favicon never flips orange because of a build for a
 * resource the user can't see on the page they're on.
 *
 * Shape:
 *  - `type: null`            → not on Sites/Functions at all (Databases, Auth, etc.)
 *  - `type: 'site' | 'function', resourceId: null`  → on the section index/list
 *  - `type, resourceId: string` → on a specific site/function detail page
 *
 * Notifications still fire globally for builds we already started tracking —
 * the whole point is to alert you when you're elsewhere — but we only *start*
 * tracking a build (and only swap the favicon) when its resource matches the
 * page you're currently working on.
 */
type DeploymentResource = 'site' | 'function'

interface RelevanceContext {
  type: DeploymentResource | null
  resourceId: string | null
}

/**
 * URL segments that look like a `$siteId` / `$functionId` slot but are
 * actually sibling routes (create wizards, listing utilities, etc.). These
 * should fall back to "section index" relevance, not "resource detail".
 */
const RESERVED_SECTION_SEGMENTS = new Set([
  'create',
  'usage',
  'templates',
  'editor',
  'index',
])

function parseRelevance(pathname: string): RelevanceContext {
  const sites = pathname.match(/\/projects\/[^/]+\/sites(?:\/([^/]+))?/)
  if (sites) {
    const seg = sites[1] ?? null
    const resourceId = seg && !RESERVED_SECTION_SEGMENTS.has(seg) ? seg : null
    return { type: 'site', resourceId }
  }
  const fns = pathname.match(/\/projects\/[^/]+\/functions(?:\/([^/]+))?/)
  if (fns) {
    const seg = fns[1] ?? null
    const resourceId = seg && !RESERVED_SECTION_SEGMENTS.has(seg) ? seg : null
    return { type: 'function', resourceId }
  }
  return { type: null, resourceId: null }
}

function isUpdateRelevant(
  ctx: RelevanceContext,
  update: { resourceType: DeploymentResource; resourceId: string },
): boolean {
  if (ctx.type === null) return false
  if (ctx.type !== update.resourceType) return false
  if (ctx.resourceId !== null && ctx.resourceId !== update.resourceId) {
    return false
  }
  return true
}

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

let permissionPromptShown = false

function notificationsSupported(): boolean {
  return typeof window !== 'undefined' && typeof Notification !== 'undefined'
}

function userOptedOut(): boolean {
  if (typeof window === 'undefined') return false
  try {
    return window.localStorage.getItem(OPT_OUT_STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

function rememberUserOptedOut(): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(OPT_OUT_STORAGE_KEY, '1')
  } catch {
    // Ignore storage write failures (private mode, etc.)
  }
}

/**
 * Show an in-app prompt asking the user to enable browser notifications. We
 * surface this only when we've actually seen a build kick off, so the request
 * is contextual. The button click is a real user gesture, which is required
 * for `Notification.requestPermission()` to actually display a prompt in
 * Safari, Firefox, and recent Chrome.
 */
function handleEnableClick(toastId: string | number): void {
  try {
    const result = Notification.requestPermission()
    const handle = (perm: NotificationPermission) => {
      toast.dismiss(toastId)
      if (perm === 'granted') {
        // Some browsers (notably Chrome on macOS) require a page reload after
        // a fresh permission grant before the page can actually construct
        // Notifications. Reload automatically so the user doesn't have to do
        // it themselves - we briefly flash a toast so the reload isn't a
        // total surprise.
        toast.success('Notifications enabled', {
          description: 'Reloading to apply…',
          duration: 1500,
        })
        window.setTimeout(() => {
          try {
            window.location.reload()
          } catch {
            // Ignore - if reload is blocked, notifications will still start
            // working on the next manual navigation.
          }
        }, 800)
      } else if (perm === 'denied') {
        rememberUserOptedOut()
        toast.message('Notifications blocked', {
          description:
            'You can re-enable them anytime from your browser settings.',
        })
      }
    }
    if (
      result &&
      typeof (result as Promise<NotificationPermission>).then === 'function'
    ) {
      void (result as Promise<NotificationPermission>).then(handle)
    } else if (typeof result === 'string') {
      handle(result as NotificationPermission)
    }
  } catch {
    // Ignore - some browsers throw if the call comes too late after the click.
  }
}

function maybeShowEnableToast(): void {
  if (permissionPromptShown) return
  if (!notificationsSupported()) return
  if (Notification.permission !== 'default') return
  if (userOptedOut()) return
  permissionPromptShown = true

  toast.custom(
    (toastId) => (
      <div className="w-[360px] rounded-xl border border-border bg-popover p-4 text-popover-foreground shadow-lg">
        <div className="flex items-start gap-3">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <Bell className="h-4 w-4" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="text-[13px] font-semibold leading-5">
              Get notified when builds finish
            </div>
            <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
              Allow browser notifications to hear about build completion, even
              from another tab.
            </p>
          </div>
        </div>
        <div className="mt-3 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => {
              rememberUserOptedOut()
              toast.dismiss(toastId)
            }}
            className="h-8 rounded-md px-3 text-[12px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Not now
          </button>
          <button
            type="button"
            onClick={() => handleEnableClick(toastId)}
            className="h-8 rounded-md bg-primary px-3 text-[12px] font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Enable
          </button>
        </div>
      </div>
    ),
    { duration: 20_000 },
  )
}

interface BuildNotificationsProviderProps {
  projectId: string
}

export function BuildNotificationsProvider({
  projectId,
}: BuildNotificationsProviderProps) {
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const queryClient = useQueryClient()
  const location = useLocation()
  const relevanceCtx = parseRelevance(location.pathname)
  // Stringified context so the route-change effect re-runs whenever the
  // user navigates between sections OR between specific resources.
  const relevanceKey = `${relevanceCtx.type ?? ''}::${relevanceCtx.resourceId ?? ''}`

  // Stable refs so the realtime handler always sees the latest state.
  const activeBuildsRef = useRef<Map<string, ActiveBuild>>(new Map())
  /**
   * Deployment IDs we've actually observed in their in-progress phase. Only
   * these are eligible to drive a success/failure favicon when they reach a
   * terminal status - this prevents stray red flashes from terminal events
   * for unrelated deployments (other resources, replays, stale cache hits,
   * etc.) that we never saw start.
   */
  const trackedRef = useRef<Set<string>>(new Set())
  /**
   * Deployment IDs we've already fired a terminal notification for. Prevents
   * duplicate notifications when realtime emits multiple update events with
   * the same final status.
   */
  const notifiedTerminalRef = useRef<Set<string>>(new Set())
  const resetTimerRef = useRef<number | null>(null)
  const originalFaviconRef = useRef<FaviconVariant | null>(null)
  const lastDesiredFaviconRef = useRef<FaviconVariant | null>(null)
  const projectIdRef = useRef(projectId)
  projectIdRef.current = projectId
  const queryClientRef = useRef(queryClient)
  queryClientRef.current = queryClient
  const setFaviconRef = useRef(setFavicon)
  setFaviconRef.current = setFavicon
  const getCurrentFaviconRef = useRef(getCurrentFavicon)
  getCurrentFaviconRef.current = getCurrentFavicon
  const relevanceCtxRef = useRef<RelevanceContext>(relevanceCtx)
  relevanceCtxRef.current = relevanceCtx

  useEffect(() => {
    if (typeof window === 'undefined') return

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

    /**
     * Set the "desired" favicon for the current build state. The actual
     * `<link rel='icon'>` is only updated when the user is on a build-relevant
     * route. When `target` is `null`, the favicon should go back to whatever
     * it was before we touched it.
     */
    function applyDesiredFavicon(target: FaviconVariant | null): void {
      lastDesiredFaviconRef.current = target
      // Only paint when the user is on a Sites/Functions page; otherwise the
      // navigation effect below will paint when they come back.
      if (relevanceCtxRef.current.type === null) return
      if (target === null) {
        const original = originalFaviconRef.current ?? 'default'
        setFaviconRef.current(original)
      } else {
        captureOriginalFavicon()
        setFaviconRef.current(target)
      }
    }

    function restoreOriginalFavicon(): void {
      applyDesiredFavicon(null)
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
      if (!notificationsSupported()) return
      // Skip only when the user is actively looking at this page. We use
      // `document.hasFocus()` instead of `visibilityState` because the latter
      // is only `hidden` when another tab is in front - it stays `visible`
      // when the user switches to a completely different app (Slack, IDE,
      // etc.), which is exactly when we *do* want a notification.
      const inForeground =
        typeof document.hasFocus === 'function'
          ? document.hasFocus() && document.visibilityState === 'visible'
          : document.visibilityState === 'visible'
      if (inForeground) return
      if (Notification.permission !== 'granted') {
        // Permission was never granted - nudge the user to enable it (this
        // becomes a no-op after the first nudge). Only surface the prompt on
        // build-relevant pages so users in unrelated sections aren't pinged.
        if (relevanceCtxRef.current.type !== null) {
          maybeShowEnableToast()
        }
        return
      }
      try {
        const notification = new Notification(title, {
          body,
          icon: '/logo.svg',
          tag,
          // Keep on screen until the user acknowledges it; build results are
          // worth more than the default ~4 second auto-dismiss.
          requireInteraction: true,
          // Make sure the OS re-alerts even if a previous notification with
          // the same tag is still showing.
          renotify: true,
        } as NotificationOptions)
        // Bring the tab to the foreground if the user clicks the notification.
        notification.onclick = () => {
          try {
            window.focus()
            notification.close()
          } catch {
            // Ignore focus failures (popup blocker, cross-origin, etc.)
          }
        }
      } catch (err) {
        // Some browsers throw when constructing a Notification fails (e.g.
        // Safari without a Service Worker, or when the OS-level permission
        // was revoked between checks). Surface it so we can see why nothing
        // showed up.
        console.warn('[BuildNotifications] failed to show notification', err)
      }
    }

    function handleStatusChange(update: {
      deploymentId: string
      resourceId: string
      resourceType: DeploymentResource
      status: string
    }): void {
      const builds = activeBuildsRef.current
      const tracked = trackedRef.current

      if (isInProgressStatus(update.status)) {
        const isNew = !builds.has(update.deploymentId)
        // Strict relevance gate: a brand new build only counts if it belongs
        // to the resource the user is currently looking at. This prevents the
        // favicon from going orange for builds the user can't see on screen
        // (e.g. Site B's build while they're on Site A, or any function build
        // while they're on the Sites overview). Builds we already started
        // tracking continue through to completion, so navigating away mid-
        // build doesn't make us lose interest.
        if (isNew && !isUpdateRelevant(relevanceCtxRef.current, update)) {
          return
        }
        // Re-arm dedupe if the same deployment ID restarts (rare, but harmless).
        notifiedTerminalRef.current.delete(update.deploymentId)
        builds.set(update.deploymentId, { ...update })
        tracked.add(update.deploymentId)
        clearResetTimer()
        applyDesiredFavicon('theme-orange')
        // First time we see this build start, nudge the user to enable browser
        // notifications so they hear about completion. Already gated by the
        // relevance check above, so we know we're on the right page.
        if (isNew) {
          maybeShowEnableToast()
        }
        return
      }

      if (!isTerminalStatus(update.status)) return

      // Only react to deployments we observed in their in-progress phase.
      // Without this guard, a stray terminal event for an unrelated deployment
      // (concurrent function build, replayed event, stale realtime payload,
      // etc.) would flash the favicon red/green even though the user's actual
      // build succeeded.
      const wasTracked = tracked.has(update.deploymentId)
      if (!wasTracked) {
        // Clean up bookkeeping in case it was added via cache seeding without
        // a real in-progress event, and bail out without touching the favicon
        // or firing a notification.
        builds.delete(update.deploymentId)
        return
      }

      // Dedupe so multiple realtime updates for the same final status only
      // produce one notification.
      if (notifiedTerminalRef.current.has(update.deploymentId)) {
        builds.delete(update.deploymentId)
        return
      }
      notifiedTerminalRef.current.add(update.deploymentId)
      builds.delete(update.deploymentId)
      tracked.delete(update.deploymentId)

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
        if (canceled) {
          // Canceling is a user action, not an error - just go back to normal.
          applyDesiredFavicon(null)
        } else {
          applyDesiredFavicon(failed ? 'theme-red' : 'theme-green')
          scheduleFaviconReset()
        }
      } else {
        // Other builds still running - keep the in-progress favicon.
        applyDesiredFavicon('theme-orange')
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

    /**
     * Walk the React Query cache for any deployments that are already
     * in-progress when this provider mounts (e.g. user navigated in mid-build,
     * or just reloaded the page) so the favicon reflects reality immediately
     * without waiting for the next realtime event.
     */
    function seedFromCache(): void {
      const cache = queryClientRef.current.getQueryCache()
      cache.getAll().forEach((query) => {
        const key = query.queryKey
        if (!Array.isArray(key) || key.length < 3) return
        const [a, b, projId] = key as unknown[]
        if (projId !== projectIdRef.current) return
        let resourceType: DeploymentResource | null = null
        if (b === 'site') resourceType = 'site'
        else if (b === 'function') resourceType = 'function'
        else return

        const data = query.state.data as
          | Models.Deployment
          | { deployments?: Models.Deployment[] }
          | undefined
        if (!data) return

        const deployments: Models.Deployment[] =
          a === 'deployments' && 'deployments' in data && Array.isArray(data.deployments)
            ? data.deployments
            : a === 'deployment' && '$id' in data
              ? [data as Models.Deployment]
              : []

        for (const dep of deployments) {
          if (!dep || typeof dep !== 'object') continue
          const status = (dep as { status?: string }).status
          if (!status || !isInProgressStatus(status)) continue
          const depId = (dep as { $id?: string }).$id
          const resId = (dep as { resourceId?: string }).resourceId
          if (!depId || !resId) continue
          if (activeBuildsRef.current.has(depId)) continue
          // Same relevance gate as live events - only seed builds the user is
          // currently looking at, otherwise reloading mid-build on (e.g.) the
          // Databases page would still flip the favicon orange.
          if (
            !isUpdateRelevant(relevanceCtxRef.current, {
              resourceType,
              resourceId: resId,
            })
          ) {
            continue
          }
          activeBuildsRef.current.set(depId, {
            deploymentId: depId,
            resourceId: resId,
            resourceType,
            status,
          })
          // Mark as tracked so the eventual terminal event is allowed to
          // change the favicon and fire a notification.
          trackedRef.current.add(depId)
        }
      })

      if (activeBuildsRef.current.size > 0) {
        clearResetTimer()
        applyDesiredFavicon('theme-orange')
      }
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

    seedFromCache()

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
      clearResetTimer()
      // If we tweaked the favicon, make sure we leave it as the user found it.
      if (originalFaviconRef.current) {
        restoreOriginalFavicon()
      }
      activeBuildsRef.current.clear()
      trackedRef.current.clear()
      notifiedTerminalRef.current.clear()
      originalFaviconRef.current = null
      lastDesiredFaviconRef.current = null
      void Promise.all([
        unregisterMain ? unregisterMain() : Promise.resolve(),
        unregisterRegional ? unregisterRegional() : Promise.resolve(),
      ])
    }
  }, [projectId])

  // Re-sync the favicon whenever the user navigates between sections (or
  // between specific resources within a section). Realtime tracking keeps
  // running globally for already-tracked builds, but the visible favicon swap
  // is gated to the resource the user is currently looking at - so navigating
  // from Site A to Site B while A is building should drop A's orange icon,
  // and a build started on Site A should not show on Site B.
  useEffect(() => {
    if (typeof window === 'undefined') return
    const ctx = relevanceCtxRef.current
    // Are any currently-tracked builds relevant to the page we just landed on?
    let hasRelevantActive = false
    activeBuildsRef.current.forEach((b) => {
      if (
        isUpdateRelevant(ctx, {
          resourceType: b.resourceType,
          resourceId: b.resourceId,
        })
      ) {
        hasRelevantActive = true
      }
    })

    if (ctx.type !== null && hasRelevantActive) {
      if (!originalFaviconRef.current) {
        const current = getCurrentFaviconRef.current()
        originalFaviconRef.current = current ?? 'default'
      }
      setFaviconRef.current('theme-orange')
    } else if (originalFaviconRef.current) {
      // Either we walked away from Sites/Functions entirely, or there's
      // nothing on this specific page that's still building - put the favicon
      // back so the user isn't visually pinged about something they can't see.
      setFaviconRef.current(originalFaviconRef.current)
    }
  }, [relevanceKey])

  return null
}
