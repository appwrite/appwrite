/**
 * BuildNotificationsProvider
 *
 * Surfaces ongoing site / function deployments outside the active tab:
 *
 * - Swaps the favicon to the blue (in-progress) variant while one or more builds
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
 * - Sends a desktop Notification when a tracked build completes. Notifications
 *   are suppressed only while this tab is actively focused so we do not spam
 *   users who can already see the UI update. Any site or function deployment
 *   in the project is tracked once it enters an in-progress state, so you
 *   still get alerted after switching tabs or navigating to another section.
 *   Permission is requested via an in-app toast button so the request happens
 *   inside a real user gesture, which most browsers require.
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
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useFavicon, type FaviconApplyMeta, type FaviconVariant } from '@/hooks/use-favicon'
import { getDefaultFaviconVariant, isStatusFaviconVariant } from '@/lib/favicon'
import { usesThemeAwareFaviconHost } from '@/lib/utils/theme-favicon-host'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'
import { registerRegionalConsoleRealtimeListener } from '@/lib/realtime/regional-console-hub'
import { PROJECT_CHANNELS } from '@/lib/realtime/constants'
import { isDeploymentTimeout } from '@/lib/utils/deployment-status'
import { resolveDeploymentResourceId } from '@/lib/realtime/deployment-events'
import {
  restoreBuildTracking,
  saveBuildTrackingBeforeReload,
} from '@/lib/browser-alerts/session-tracking'
import {
  isPageInBackground,
  notificationsSupported,
  requestNotificationPermission,
  showBrowserAlert,
} from '@/lib/browser-alerts/browser-notifications'
import { useT } from '@/lib/i18n/translate'
import { useBuildNotificationsOptedOut } from '@/lib/react-query/hooks'

interface ActiveBuild {
  deploymentId: string
  resourceId: string
  resourceType: DeploymentResource
  status: string
  /** Used with `isDeploymentTimeout` so stale `building` rows do not keep the blue favicon */
  createdAt?: string | null
}

/** How long the success/failure favicon stays before we restore the original. */
const TERMINAL_FAVICON_RESET_MS = 10_000

/**
 * What the user is currently looking at, used to decide whether a deployment
 * event should affect the favicon / surface a permission prompt. We want to be
 * strict here so the favicon never flips blue because of a build for a
 * resource the user can't see on the page they're on.
 *
 * Shape:
 *  - `type: null`            → not on Sites/Functions at all (Databases, Auth, etc.)
 *  - `type: 'site' | 'function', resourceId: null`  → on the section index/list
 *  - `type, resourceId: string` → on a specific site/function detail page
 *
 * Notifications still fire globally for builds we already started tracking -
 * the whole point is to alert you when you're elsewhere. Favicon swaps stay
 * scoped to the resource the user is currently viewing so unrelated builds do
 * not change the tab icon.
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
    status === 'timeout' ||
    status === 'canceled' ||
    status === 'cancelled'
  )
}

function countRelevantActiveBuilds(
  builds: Map<string, ActiveBuild>,
  ctx: RelevanceContext,
): number {
  let count = 0
  builds.forEach((build) => {
    if (isDeploymentTimeout(build.status, build.createdAt)) return
    if (
      isUpdateRelevant(ctx, {
        resourceType: build.resourceType,
        resourceId: build.resourceId,
      })
    ) {
      count++
    }
  })
  return count
}

/** Match base favicon: theme variants in dev/local, flat logo colors in prod. */
function buildInProgressFavicon(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme-blue' : 'blue'
}

function buildSuccessFavicon(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme-green' : 'green'
}

function buildFailureFavicon(): FaviconVariant {
  return usesThemeAwareFaviconHost() ? 'theme-red' : 'red'
}

function buildMeta(
  reason: string,
  builds: Iterable<ActiveBuild>,
  extras?: {
    projectId?: string
    projectName?: string
    organizationId?: string
    pathname?: string
  },
): FaviconApplyMeta {
  const list = Array.from(builds)
  return {
    source: 'build-notifications',
    reason,
    detail: formatActiveBuildsDetail(list),
    context: {
      projectId: extras?.projectId,
      projectName: extras?.projectName,
      organizationId: extras?.organizationId,
      pathname: extras?.pathname,
      resources: list.map((b) => ({
        type: b.resourceType,
        id: b.resourceId,
        status: b.status,
        deploymentId: b.deploymentId,
      })),
    },
  }
}

function formatActiveBuildsDetail(
  builds: Iterable<ActiveBuild> | ActiveBuild[],
): string | undefined {
  const list = Array.isArray(builds) ? builds : Array.from(builds)
  if (list.length === 0) return undefined
  return list
    .map(
      (b) =>
        `${b.resourceType} ${b.resourceId} · ${b.status} · deployment ${b.deploymentId}`,
    )
    .join('; ')
}

let permissionPromptShown = false

/**
 * Show an in-app prompt asking the user to enable browser notifications. We
 * surface this only when we've actually seen a build kick off, so the request
 * is contextual. The button click is a real user gesture, which is required
 * for `Notification.requestPermission()` to actually display a prompt in
 * Safari, Firefox, and recent Chrome.
 */
function handleEnableClick(
  toastId: string | number,
  rememberOptedOut: () => void,
  translate: (text: string) => string,
  snapshotBeforeReload?: () => void,
): void {
  void (async () => {
    const perm = await requestNotificationPermission()
    toast.dismiss(toastId)
    if (perm === 'granted') {
      snapshotBeforeReload?.()
      toast.success(translate('Notifications enabled'), {
        description: translate('Reloading to apply…'),
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
      rememberOptedOut()
      toast.message(translate('Notifications blocked'), {
        description: translate(
          'You can re-enable them anytime from your browser settings.',
        ),
      })
    }
  })()
}

function maybeShowEnableToast(
  rememberOptedOut: () => void,
  optedOut: boolean,
  translate: (text: string) => string,
  snapshotBeforeReload?: () => void,
): void {
  if (permissionPromptShown) return
  if (!notificationsSupported()) return
  if (Notification.permission !== 'default') return
  if (optedOut) return
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
              {translate('Get notified when builds finish')}
            </div>
            <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
              {translate(
                'Allow browser notifications to hear about build completion, even from another tab.',
              )}
            </p>
          </div>
        </div>
        <div className="mt-3 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => {
              rememberOptedOut()
              toast.dismiss(toastId)
            }}
            className="h-8 rounded-md px-3 text-[12px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {translate('Not now')}
          </button>
          <button
            type="button"
            onClick={() =>
              handleEnableClick(
                toastId,
                rememberOptedOut,
                translate,
                snapshotBeforeReload,
              )
            }
            className="h-8 rounded-md bg-primary px-3 text-[12px] font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {translate('Enable')}
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
  const { account } = useAuth()
  const t = useT()
  const tRef = useRef(t)
  tRef.current = t
  const { optedOut, setOptedOut } = useBuildNotificationsOptedOut(account)
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const queryClient = useQueryClient()
  const location = useLocation()
  const optedOutRef = useRef(optedOut)
  optedOutRef.current = optedOut
  const rememberOptedOutRef = useRef(() => setOptedOut(true))
  rememberOptedOutRef.current = () => setOptedOut(true)
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
  /** True after the user leaves this tab/window while a project build may be running. */
  const userWasAwayRef = useRef(false)
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
  const pathnameRef = useRef(location.pathname)
  pathnameRef.current = location.pathname

  useEffect(() => {
    if (typeof window === 'undefined') return

    function lookupProjectMeta(): {
      projectId: string
      projectName?: string
      organizationId?: string
    } {
      const projectId = projectIdRef.current
      const cached = queryClientRef.current.getQueryData<{
        name?: string
        teamId?: string
      }>(['project', projectId])
      return {
        projectId,
        projectName: cached?.name?.trim() || undefined,
        organizationId: cached?.teamId?.trim() || undefined,
      }
    }

    function snapshotBeforeReload(): void {
      saveBuildTrackingBeforeReload(
        projectIdRef.current,
        activeBuildsRef.current.values(),
        trackedRef.current,
      )
    }

    function captureOriginalFavicon(): void {
      if (originalFaviconRef.current) return
      const current = getCurrentFaviconRef.current()
      const resolved = current ?? getDefaultFaviconVariant()
      originalFaviconRef.current = isStatusFaviconVariant(resolved)
        ? getDefaultFaviconVariant()
        : resolved
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
    function applyDesiredFavicon(
      target: FaviconVariant | null,
      reason: string,
    ): void {
      lastDesiredFaviconRef.current = target
      // Only paint when the user is on a Sites/Functions page; otherwise the
      // navigation effect below will paint when they come back.
      if (relevanceCtxRef.current.type === null) return
      const projectMeta = lookupProjectMeta()
      const builds = Array.from(activeBuildsRef.current.values())
      const meta = buildMeta(reason, builds, {
        ...projectMeta,
        pathname: pathnameRef.current,
      })
      if (meta.context) {
        meta.context.resources = builds.map((b) => ({
          type: b.resourceType,
          id: b.resourceId,
          name: lookupResourceName(b.resourceType, b.resourceId),
          status: b.status,
          deploymentId: b.deploymentId,
        }))
      }
      if (target === null) {
        const original = originalFaviconRef.current ?? getDefaultFaviconVariant()
        setFaviconRef.current(original, meta)
        originalFaviconRef.current = null
      } else {
        captureOriginalFavicon()
        setFaviconRef.current(target, meta)
      }
    }

    function restoreOriginalFavicon(reason = 'Restored after builds settled'): void {
      applyDesiredFavicon(null, reason)
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

    function markUserAway(): void {
      userWasAwayRef.current = true
    }

    function syncDeploymentsFromCache(): void {
      queryClientRef.current.getQueryCache().getAll().forEach((query) => {
        inspectDeploymentQuery(query)
      })
    }

    function refetchTrackedDeployments(): void {
      const qc = queryClientRef.current
      const pid = projectIdRef.current
      activeBuildsRef.current.forEach((build) => {
        const listKey =
          build.resourceType === 'site'
            ? ['deployments', 'site', pid, build.resourceId]
            : ['deployments', 'function', pid, build.resourceId]
        const detailKey =
          build.resourceType === 'site'
            ? ['deployment', 'site', pid, build.resourceId, build.deploymentId]
            : [
                'deployment',
                'function',
                pid,
                build.resourceId,
                build.deploymentId,
              ]
        void qc.refetchQueries({ queryKey: listKey, exact: false })
        void qc.refetchQueries({ queryKey: detailKey, exact: false })
      })
    }

    function processCachedDeployment(
      dep: Record<string, unknown>,
      resourceType: DeploymentResource,
      resourceId: string,
    ): void {
      const deploymentId = dep.$id as string | undefined
      const status = dep.status as string | undefined
      if (!deploymentId || !status) return

      handleStatusChange({
        deploymentId,
        resourceId,
        resourceType,
        status,
        createdAt: dep.$createdAt as string | undefined,
      })
    }

    function inspectDeploymentQuery(query: {
      queryKey: readonly unknown[]
      state: { data: unknown }
    }): void {
      const key = query.queryKey
      if (!Array.isArray(key) || key.length < 4) return

      const [a, b, projId] = key as unknown[]
      if (projId !== projectIdRef.current) return
      if (b !== 'site' && b !== 'function') return
      const resourceType = b as DeploymentResource

      const data = query.state.data as
        | Record<string, unknown>
        | { deployments?: Array<Record<string, unknown>> }
        | undefined
      if (!data) return

      if (a === 'deployment') {
        const resourceId = key[3]
        if (typeof resourceId !== 'string') return
        processCachedDeployment(data as Record<string, unknown>, resourceType, resourceId)
        return
      }

      if (a === 'deployments') {
        const resourceId = key[3]
        if (typeof resourceId !== 'string') return
        const deployments = (data as { deployments?: Array<Record<string, unknown>> })
          .deployments
        if (!Array.isArray(deployments)) return
        for (const dep of deployments) {
          if (!dep || typeof dep !== 'object') continue
          processCachedDeployment(dep, resourceType, resourceId)
        }
      }
    }

    function shouldDeliverBuildAlert(): boolean {
      return isPageInBackground() || userWasAwayRef.current
    }

    function notify(title: string, body: string, tag: string): void {
      if (optedOutRef.current) return
      if (Notification.permission !== 'granted') {
        if (relevanceCtxRef.current.type !== null) {
          maybeShowEnableToast(
            () => rememberOptedOutRef.current(),
            optedOutRef.current,
            tRef.current,
            snapshotBeforeReload,
          )
        }
        return
      }
      showBrowserAlert(title, body, {
        tag: `${tag}-${Date.now()}`,
        force: true,
      })
    }

    function handleStatusChange(update: {
      deploymentId: string
      resourceId: string
      resourceType: DeploymentResource
      status: string
      createdAt?: string | null
    }): void {
      const builds = activeBuildsRef.current
      const tracked = trackedRef.current

      const createdAtForTimeout =
        update.createdAt ?? builds.get(update.deploymentId)?.createdAt

      // API can still say building while the UI treats the deployment as timed out
      if (
        isInProgressStatus(update.status) &&
        isDeploymentTimeout(update.status, createdAtForTimeout)
      ) {
        const had = builds.has(update.deploymentId)
        builds.delete(update.deploymentId)
        tracked.delete(update.deploymentId)
        if (had) {
          if (builds.size === 0) {
            applyDesiredFavicon(
              null,
              'Restored after timed-out build removed from active set',
            )
          } else {
            applyDesiredFavicon(
              buildInProgressFavicon(),
              `${builds.size} build(s) still in progress after timeout cleanup`,
            )
          }
        }
        return
      }

      if (isInProgressStatus(update.status)) {
        const isNew = !builds.has(update.deploymentId)
        // Track every site/function deployment in this project so background
        // notifications still fire after the user switches tabs or navigates
        // away from Sites/Functions. Favicon updates stay scoped below.
        notifiedTerminalRef.current.delete(update.deploymentId)
        builds.set(update.deploymentId, {
          ...update,
          createdAt: createdAtForTimeout ?? update.createdAt,
        })
        tracked.add(update.deploymentId)

        const isRelevant = isUpdateRelevant(relevanceCtxRef.current, update)
        if (isRelevant) {
          clearResetTimer()
          applyDesiredFavicon(
            buildInProgressFavicon(),
            `${countRelevantActiveBuilds(builds, relevanceCtxRef.current)} ${update.resourceType} build(s) in progress on this route`,
          )
        }

        if (isNew) {
          maybeShowEnableToast(
            () => rememberOptedOutRef.current(),
            optedOutRef.current,
            tRef.current,
            snapshotBeforeReload,
          )
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

      const failed = update.status === 'failed'
      const canceled =
        update.status === 'canceled' || update.status === 'cancelled'
      const timedOut = update.status === 'timeout'
      const name = lookupResourceName(update.resourceType, update.resourceId)
      const label = update.resourceType === 'site' ? 'Site' : 'Function'
      const verb =
        update.status === 'ready'
          ? 'completed successfully'
          : update.status === 'failed'
            ? 'failed'
            : timedOut
              ? 'timed out'
              : 'was canceled'
      const notifyResultWord =
        update.status === 'ready'
          ? 'ready'
          : update.status === 'failed'
            ? 'failed'
            : timedOut
              ? 'timed out'
              : 'canceled'
      if (shouldDeliverBuildAlert()) {
        notify(
          tRef.current(`${label} build ${notifyResultWord}`),
          `${name} ${tRef.current(`build ${verb}.`)}`,
          `appwrite-build-${update.deploymentId}`,
        )
      }

      if (activeBuildsRef.current.size === 0) {
        userWasAwayRef.current = false
      }

      const completingBuildWasRelevant = isUpdateRelevant(
        relevanceCtxRef.current,
        update,
      )
      const relevantRemaining = countRelevantActiveBuilds(
        builds,
        relevanceCtxRef.current,
      )

      if (!completingBuildWasRelevant) {
        return
      }

      if (relevantRemaining === 0) {
        if (canceled) {
          // Canceling is a user action, not an error - just go back to normal.
          applyDesiredFavicon(
            null,
            `Restored after ${update.resourceType} build was canceled`,
          )
        } else {
          applyDesiredFavicon(
            failed || timedOut ? buildFailureFavicon() : buildSuccessFavicon(),
            failed || timedOut
              ? `${label} build ${timedOut ? 'timed out' : 'failed'}`
              : `${label} build completed successfully`,
          )
          scheduleFaviconReset()
        }
      } else {
        // Other relevant builds still running - keep the in-progress favicon.
        applyDesiredFavicon(
          buildInProgressFavicon(),
          `${relevantRemaining} relevant build(s) still in progress after another finished`,
        )
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

      const resourceTypeRaw =
        (payload.resourceType as string | undefined) ??
        (isSite ? 'site' : 'function')
      const resourceType: DeploymentResource =
        resourceTypeRaw === 'function' ? 'function' : 'site'

      const resourceId = resolveDeploymentResourceId(
        events,
        resourceType,
        payload,
      )
      if (!resourceId) return

      const createdAt = payload.$createdAt as string | undefined

      handleStatusChange({
        deploymentId,
        resourceId,
        resourceType,
        status,
        createdAt,
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
          const resourceIdFromKey =
            typeof key[3] === 'string' ? (key[3] as string) : undefined
          const resId =
            (dep as { resourceId?: string }).resourceId ?? resourceIdFromKey
          const createdAt = (dep as { $createdAt?: string }).$createdAt
          if (!depId || !resId) continue
          if (isDeploymentTimeout(status, createdAt)) continue
          if (activeBuildsRef.current.has(depId)) continue
          activeBuildsRef.current.set(depId, {
            deploymentId: depId,
            resourceId: resId,
            resourceType,
            status,
            createdAt,
          })
          // Mark as tracked so the eventual terminal event can fire a
          // notification even if the user navigated away from Sites/Functions.
          trackedRef.current.add(depId)
        }
      })

      if (
        countRelevantActiveBuilds(
          activeBuildsRef.current,
          relevanceCtxRef.current,
        ) > 0
      ) {
        clearResetTimer()
        applyDesiredFavicon(
          buildInProgressFavicon(),
          `${countRelevantActiveBuilds(activeBuildsRef.current, relevanceCtxRef.current)} relevant build(s) in progress (seeded from cache)`,
        )
      }
    }

    function onVisibilityChange(): void {
      if (document.visibilityState === 'hidden') {
        markUserAway()
        return
      }

      // Tab woke up: browsers often defer realtime/timer work while hidden, so
      // re-read the cache and refetch tracked deployments to catch completions
      // we missed while the tab was in the background.
      syncDeploymentsFromCache()
      if (activeBuildsRef.current.size > 0 || trackedRef.current.size > 0) {
        refetchTrackedDeployments()
      } else {
        userWasAwayRef.current = false
      }

      // User is looking at us again - drop any "completed" indicator immediately
      // so the favicon doesn't keep nagging once they've seen the result.
      if (activeBuildsRef.current.size === 0 && originalFaviconRef.current) {
        clearResetTimer()
        restoreOriginalFavicon(
          'Restored when tab became visible after builds settled',
        )
      }
    }

    function onWindowBlur(): void {
      markUserAway()
    }

    document.addEventListener('visibilitychange', onVisibilityChange)
    window.addEventListener('blur', onWindowBlur)

    const unsubscribeCache = queryClientRef.current
      .getQueryCache()
      .subscribe((event) => {
        if (event.type !== 'updated') return
        inspectDeploymentQuery(event.query)
      })

    const restoredBuilds = restoreBuildTracking(projectIdRef.current)
    if (restoredBuilds) {
      for (const build of restoredBuilds) {
        activeBuildsRef.current.set(build.deploymentId, build)
        trackedRef.current.add(build.deploymentId)
      }
      if (
        countRelevantActiveBuilds(
          activeBuildsRef.current,
          relevanceCtxRef.current,
        ) > 0
      ) {
        clearResetTimer()
        applyDesiredFavicon(
          buildInProgressFavicon(),
          `${countRelevantActiveBuilds(activeBuildsRef.current, relevanceCtxRef.current)} relevant build(s) in progress (restored after permission reload)`,
        )
      }
    }

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
      window.removeEventListener('blur', onWindowBlur)
      unsubscribeCache()
      clearResetTimer()
      // If we tweaked the favicon, make sure we leave it as the user found it.
      if (originalFaviconRef.current) {
        restoreOriginalFavicon('Restored on build-notifications provider unmount')
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
  // from Site A to Site B while A is building should drop A's blue icon,
  // and a build started on Site A should not show on Site B.
  useEffect(() => {
    if (typeof window === 'undefined') return
    const ctx = relevanceCtxRef.current
    // Are any currently-tracked builds relevant to the page we just landed on?
    let hasRelevantActive = false
    activeBuildsRef.current.forEach((b) => {
      if (isDeploymentTimeout(b.status, b.createdAt)) return
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
        const resolved = current ?? getDefaultFaviconVariant()
        originalFaviconRef.current = isStatusFaviconVariant(resolved)
          ? getDefaultFaviconVariant()
          : resolved
      }
      const cached = queryClientRef.current.getQueryData<{
        name?: string
        teamId?: string
      }>(['project', projectIdRef.current])
      const builds = Array.from(activeBuildsRef.current.values())
      setFaviconRef.current(
        buildInProgressFavicon(),
        buildMeta(
          `Relevant ${ctx.type} build(s) in progress on this route`,
          builds,
          {
            projectId: projectIdRef.current,
            projectName: cached?.name?.trim() || undefined,
            organizationId: cached?.teamId?.trim() || undefined,
            pathname: location.pathname,
          },
        ),
      )
    } else if (originalFaviconRef.current) {
      // Either we walked away from Sites/Functions entirely, or there's
      // nothing on this specific page that's still building - put the favicon
      // back so the user isn't visually pinged about something they can't see.
      const cached = queryClientRef.current.getQueryData<{
        name?: string
        teamId?: string
      }>(['project', projectIdRef.current])
      setFaviconRef.current(
        originalFaviconRef.current,
        buildMeta(
          ctx.type === null
            ? 'Restored after leaving Sites/Functions route'
            : 'Restored because no relevant builds on this route',
          activeBuildsRef.current.values(),
          {
            projectId: projectIdRef.current,
            projectName: cached?.name?.trim() || undefined,
            organizationId: cached?.teamId?.trim() || undefined,
            pathname: location.pathname,
          },
        ),
      )
      originalFaviconRef.current = null
    }
  }, [relevanceKey])

  return null
}
