import { useEffect, useState, useRef, useMemo, useReducer } from 'react'
import { useIsFetching, useIsMutating, useQueryClient } from '@tanstack/react-query'
import { useRouter, useLocation, useMatches } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import {
  isHttpForbiddenError,
  isHttpPaymentRequiredError,
  isHttpProjectAccessError,
} from '@/lib/utils/error-formatting'
import { isMarketingPage } from '@/lib/marketing/is-marketing-page'
import {
  INITIAL_LOADER_SHELL_GATE,
  getProjectIdFromPathname,
  projectRouteRequiresProjectSelectorGate,
  resetInitialLoaderShellGate,
  setInitialLoaderShellGate,
} from '@/lib/initial-loader/shell-gates'
import { useInitialLoaderShellGatesReady } from '@/hooks/use-initial-loader-shell-gates'

/**
 * True when any `['account','console', ...]` query is in error with HTTP 403.
 * Subscribes to the query cache so `/` can hide the fullscreen loader when the
 * account is blocked (no redirect off `/`).
 */
function useConsoleAccountQueryForbidden403(): boolean {
  const queryClient = useQueryClient()
  const [cacheTick, bumpCache] = useReducer((n: number) => n + 1, 0)

  useEffect(() => {
    const lastForbiddenRef = { current: false }
    return queryClient.getQueryCache().subscribe(() => {
      const queries = queryClient.getQueryCache().findAll({
        queryKey: ['account', 'console'],
      })
      const next = queries.some(
        (q) =>
          q.state.status === 'error' && isHttpForbiddenError(q.state.error),
      )
      if (next !== lastForbiddenRef.current) {
        lastForbiddenRef.current = next
        bumpCache()
      }
    })
  }, [queryClient])

  return useMemo(() => {
    const queries = queryClient.getQueryCache().findAll({
      queryKey: ['account', 'console'],
    })
    return queries.some(
      (q) =>
        q.state.status === 'error' && isHttpForbiddenError(q.state.error),
    )
  }, [queryClient, cacheTick])
}

/**
 * True when the current project query settled into 401/403/404 (or 402 budget).
 * Nested routes may never mount ProjectSelector on those paths, so the
 * fullscreen loader must not wait on the project-selector shell gate.
 */
function useProjectQueryShellGateBypass(pathname: string): boolean {
  const queryClient = useQueryClient()
  const projectId = getProjectIdFromPathname(pathname)
  const [cacheTick, bumpCache] = useReducer((n: number) => n + 1, 0)

  useEffect(() => {
    if (!projectId) return
    const lastBypassRef = { current: false }
    return queryClient.getQueryCache().subscribe((event) => {
      const query = event?.query
      if (
        query &&
        (query.queryKey[0] !== 'project' || query.queryKey[1] !== projectId)
      ) {
        return
      }
      const state = queryClient.getQueryState(['project', projectId])
      const next =
        state?.status === 'error' &&
        (isHttpProjectAccessError(state.error) ||
          isHttpPaymentRequiredError(state.error))
      if (next !== lastBypassRef.current) {
        lastBypassRef.current = next
        bumpCache()
      }
    })
  }, [queryClient, projectId])

  return useMemo(() => {
    if (!projectId) return false
    const state = queryClient.getQueryState(['project', projectId])
    return (
      state?.status === 'error' &&
      (isHttpProjectAccessError(state.error) ||
        isHttpPaymentRequiredError(state.error))
    )
  }, [queryClient, projectId, cacheTick])
}

export function useInitialLoader() {
  // Router + location need to be resolved before computing initial loader state
  const router = useRouter()
  const location = useLocation()
  const matches = useMatches()
  const isConsoleAccount403 = useConsoleAccountQueryForbidden403()
  const projectShellGateBypass = useProjectQueryShellGateBypass(
    location.pathname,
  )
  const shellGatesReadyFromSelector = useInitialLoaderShellGatesReady(
    location.pathname,
  )
  const shellGatesReady =
    shellGatesReadyFromSelector || projectShellGateBypass

  // Track all active queries and mutations (including Appwrite calls)
  const isFetching = useIsFetching({
    predicate: (query) => query.options.meta?.skipInitialLoader !== true,
  })
  const isMutating = useIsMutating()

  // Determine if we should show loader
  // Show loader for protected routes and routes that typically need data loading
  // Auth pages don't need a loader - they're simple forms that load instantly
  // Memoize to prevent recalculation on every render
  const isAuthRoute = useMemo(
    () =>
      location.pathname === '/sign-in' ||
      location.pathname === '/sign-up' ||
      location.pathname === '/recovery' ||
      location.pathname === '/mfa' ||
      location.pathname === '/join' ||
      location.pathname === '/sign-out' ||
      location.pathname === '/verify-email',
    [location.pathname],
  )

  const isInstantPublicRoute = useMemo(
    () => isOptionalAuthPage(location.pathname),
    [location.pathname],
  )

  const isMarketingRoute = useMemo(
    () => isMarketingPage({ pathname: location.pathname, matches }),
    [location.pathname, matches],
  )

  const skipStaticLoader = isAuthRoute || isInstantPublicRoute || isMarketingRoute

  // Include "/" so the branded loader shows until redirect; don't count root as "first page"
  const shouldShowLoader = useMemo(
    () =>
      !skipStaticLoader &&
      (location.pathname === '/' ||
        location.pathname.startsWith('/protected') ||
        location.pathname.startsWith('/organizations') ||
        location.pathname.startsWith('/projects') ||
        location.pathname.startsWith('/console') ||
        location.pathname.startsWith('/account') ||
        location.pathname.startsWith('/generator')),
    [location.pathname, skipStaticLoader],
  )

  // Initialize loading state synchronously so the loader is visible on first paint
  const [isLoading, setIsLoading] = useState(() => shouldShowLoader)
  const timeoutRef = useRef<NodeJS.Timeout | null>(null)
  const maxTimeoutRef = useRef<NodeJS.Timeout | null>(null)
  const startTimeRef = useRef<number | null>(
    shouldShowLoader ? Date.now() : null,
  )
  const wasLoadingRef = useRef(shouldShowLoader)
  const hasCompletedInitialLoadRef = useRef(false)

  // Use refs to track previous values and prevent unnecessary re-renders
  const prevIsFetchingRef = useRef(isFetching)
  const prevIsMutatingRef = useRef(isMutating)
  const prevRouterStatusRef = useRef(router.state.status)
  const prevPathnameRef = useRef(location.pathname)
  const prevForbidden403Ref = useRef(isConsoleAccount403)
  const prevShellGatesReadyRef = useRef(shellGatesReady)
  const prevPathnameForShellGateRef = useRef(location.pathname)

  // Reset the project-selector gate when entering a project route (not on first paint).
  useEffect(() => {
    if (prevPathnameForShellGateRef.current === location.pathname) return
    prevPathnameForShellGateRef.current = location.pathname

    if (projectRouteRequiresProjectSelectorGate(location.pathname)) {
      setInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector, false)
      return
    }
    resetInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector)
  }, [location.pathname])

  useEffect(() => {
    // After the first console paint, skip the loader for in-console navigations
    // (including agent ↔ org, since agent lives under the console shell).
    if (hasCompletedInitialLoadRef.current) {
      return
    }

    // Early return if we're on a route that shouldn't show loader
    // This prevents unnecessary processing on root/auth routes
    if (!shouldShowLoader) {
      // Don't show loader on public/auth routes
      // Auth pages mark complete immediately; "/" is handled by shouldShowLoader so we don't land here for "/"
      if (skipStaticLoader) {
        hasCompletedInitialLoadRef.current = true
      } else if (
        router.state.status === 'idle' &&
        isFetching === 0 &&
        isMutating === 0
      ) {
        // For other public routes, mark complete when idle
        hasCompletedInitialLoadRef.current = true
      }
      if (wasLoadingRef.current) {
        setIsLoading(false)
        wasLoadingRef.current = false
        startTimeRef.current = null
      }
      // Update refs but don't process further - early return prevents re-renders
      prevIsFetchingRef.current = isFetching
      prevIsMutatingRef.current = isMutating
      prevRouterStatusRef.current = router.state.status
      prevPathnameRef.current = location.pathname
      return
    }

    // From here on, we only process if shouldShowLoader is true
    // This means we're on a route that should show the loader

    // Only process if something actually changed
    const routerStatusChanged =
      prevRouterStatusRef.current !== router.state.status
    const pathnameChanged = prevPathnameRef.current !== location.pathname
    const fetchingChanged = prevIsFetchingRef.current !== isFetching
    const mutatingChanged = prevIsMutatingRef.current !== isMutating
    const forbidden403Changed =
      prevForbidden403Ref.current !== isConsoleAccount403
    const shellGatesReadyChanged =
      prevShellGatesReadyRef.current !== shellGatesReady

    // If nothing relevant changed, skip processing
    if (
      !routerStatusChanged &&
      !pathnameChanged &&
      !fetchingChanged &&
      !mutatingChanged &&
      !forbidden403Changed &&
      !shellGatesReadyChanged
    ) {
      return
    }

    // Update refs
    prevIsFetchingRef.current = isFetching
    prevIsMutatingRef.current = isMutating
    prevRouterStatusRef.current = router.state.status
    prevPathnameRef.current = location.pathname
    prevForbidden403Ref.current = isConsoleAccount403
    prevShellGatesReadyRef.current = shellGatesReady

    // Clear any existing timeouts
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
      timeoutRef.current = null
    }
    if (maxTimeoutRef.current) {
      clearTimeout(maxTimeoutRef.current)
      maxTimeoutRef.current = null
    }

    // Check if there are active requests (route loaders use ensureQueryData, so they show up here)
    const currentHasActiveRequests = isFetching > 0 || isMutating > 0

    // Show loader when router or React Query indicates loading.
    const isRouterLoading = router.state.status !== 'idle'
    const shouldShowLoadingState = isRouterLoading || currentHasActiveRequests

    // Hide loader when React Query is idle (don't wait for router).
    // On "/" we normally wait for redirect; exception: console account 403 (blocked) stays on "/".
    const shouldHideLoader =
      (location.pathname !== '/' || isConsoleAccount403) &&
      !currentHasActiveRequests &&
      shellGatesReady &&
      wasLoadingRef.current

    if (shouldShowLoadingState && !wasLoadingRef.current) {
      // Started loading
      setIsLoading(true)
      startTimeRef.current = Date.now()
      wasLoadingRef.current = true

      // Safety net: Hide loader after 20 seconds maximum to prevent infinite hanging
      maxTimeoutRef.current = setTimeout(() => {
        console.warn('Initial loader timeout - hiding loader after 20 seconds')
        setIsLoading(false)
        startTimeRef.current = null
        wasLoadingRef.current = false
        hasCompletedInitialLoadRef.current = true
        maxTimeoutRef.current = null
      }, 20000)
    } else if (shouldHideLoader) {
      // React Query idle - hide loader (even if router still pending)
      const minLoadTime = 800 // Minimum display time to prevent flashing
      const elapsedTime = startTimeRef.current
        ? Date.now() - startTimeRef.current
        : 0
      const remainingTime = Math.max(0, minLoadTime - elapsedTime)

      timeoutRef.current = setTimeout(() => {
        setIsLoading(false)
        startTimeRef.current = null
        wasLoadingRef.current = false
        hasCompletedInitialLoadRef.current = true // Mark initial load as complete
        // Clear max timeout if it exists
        if (maxTimeoutRef.current) {
          clearTimeout(maxTimeoutRef.current)
          maxTimeoutRef.current = null
        }
      }, remainingTime)
    }

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }
      if (maxTimeoutRef.current) {
        clearTimeout(maxTimeoutRef.current)
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    shouldShowLoader,
    router.state.status,
    location.pathname,
    isFetching,
    isMutating,
    isConsoleAccount403,
    shellGatesReady,
  ])

  return { isLoading, isAuthRoute, skipStaticLoader }
}
