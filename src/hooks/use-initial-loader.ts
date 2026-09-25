import { useEffect, useState, useRef, useMemo, useReducer } from 'react'
import { useIsFetching, useIsMutating, useQueryClient } from '@tanstack/react-query'
import { useRouterState, useLocation, useMatches } from '@tanstack/react-router'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'
import {
  isHttpPaymentRequiredError,
  isHttpProjectAccessError,
} from '@/lib/utils/error-formatting'
import { isMarketingPage } from '@/lib/marketing/is-marketing-page'
import {
  INITIAL_LOADER_SHELL_GATE,
  getProjectIdFromPathname,
  hasInitialLoaderCompleted,
  markInitialLoaderCompleted,
  projectRouteRequiresProjectSelectorGate,
  resetInitialLoaderShellGate,
  setInitialLoaderShellGate,
} from '@/lib/initial-loader/shell-gates'
import { useInitialLoaderShellGatesReady } from '@/hooks/use-initial-loader-shell-gates'

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
  const routerStatus = useRouterState({ select: (s) => s.status })
  const location = useLocation()
  const matches = useMatches()
  const projectShellGateBypass = useProjectQueryShellGateBypass(
    location.pathname,
  )
  const shellGatesReadyFromSelector = useInitialLoaderShellGatesReady(
    location.pathname,
  )
  const shellGatesReady =
    shellGatesReadyFromSelector || projectShellGateBypass

  // Only queries that have not produced data yet. Cached refetches and intent
  // preloads of already-warmed keys must not re-arm the branded overlay.
  const isFetching = useIsFetching({
    predicate: (query) =>
      query.options.meta?.skipInitialLoader !== true &&
      query.state.data === undefined &&
      query.state.status !== 'error',
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

  const skipStaticLoader =
    isAuthRoute || isInstantPublicRoute || isMarketingRoute

  // `/` (and legacy `/app`) are redirect hops: never show the branded overlay.
  // Do not treat them as skipStaticLoader, or the first console paint after
  // the hop would skip the overlay too.
  const shouldShowLoader = useMemo(
    () =>
      !skipStaticLoader &&
      location.pathname !== '/' &&
      location.pathname !== '/app' &&
      (location.pathname.startsWith('/protected') ||
        location.pathname.startsWith('/marketplace') ||
        location.pathname.startsWith('/organizations') ||
        location.pathname.startsWith('/projects') ||
        location.pathname.startsWith('/console') ||
        location.pathname.startsWith('/account') ||
        location.pathname.startsWith('/generator')),
    [location.pathname, skipStaticLoader],
  )

  const alreadyCompleted = hasInitialLoaderCompleted()

  // Initialize loading state synchronously so the loader is visible on first paint
  const [isLoading, setIsLoading] = useState(
    () => shouldShowLoader && !alreadyCompleted,
  )
  const timeoutRef = useRef<NodeJS.Timeout | null>(null)
  const maxTimeoutRef = useRef<NodeJS.Timeout | null>(null)
  const startTimeRef = useRef<number | null>(
    shouldShowLoader && !alreadyCompleted ? Date.now() : null,
  )
  const wasLoadingRef = useRef(shouldShowLoader && !alreadyCompleted)
  const hasCompletedInitialLoadRef = useRef(alreadyCompleted)
  const prevPathnameForShellGateRef = useRef(location.pathname)

  const completeInitialLoad = () => {
    hasCompletedInitialLoadRef.current = true
    markInitialLoaderCompleted()
  }

  const clearHideTimeout = () => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
      timeoutRef.current = null
    }
  }

  const clearMaxTimeout = () => {
    if (maxTimeoutRef.current) {
      clearTimeout(maxTimeoutRef.current)
      maxTimeoutRef.current = null
    }
  }

  // Reset the project-selector gate when entering a different project (or a
  // project route from outside). In-project navigations must not flip the gate
  // or the branded overlay reappears on every few clicks.
  useEffect(() => {
    const prevPathname = prevPathnameForShellGateRef.current
    if (prevPathname === location.pathname) return
    prevPathnameForShellGateRef.current = location.pathname

    if (!projectRouteRequiresProjectSelectorGate(location.pathname)) {
      resetInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector)
      return
    }

    const prevProjectId = getProjectIdFromPathname(prevPathname)
    const nextProjectId = getProjectIdFromPathname(location.pathname)
    if (prevProjectId && prevProjectId === nextProjectId) {
      return
    }

    setInitialLoaderShellGate(INITIAL_LOADER_SHELL_GATE.projectSelector, false)
  }, [location.pathname])

  useEffect(() => {
    return () => {
      clearHideTimeout()
      clearMaxTimeout()
    }
    // Unmount only. Clearing these on every dep change cancelled the hide timer
    // and left the overlay up until the next idle window.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    // After the first console paint, skip the loader for in-console navigations
    // (including agent ↔ org, since agent lives under the console shell).
    if (hasCompletedInitialLoadRef.current || hasInitialLoaderCompleted()) {
      if (wasLoadingRef.current) {
        setIsLoading(false)
        wasLoadingRef.current = false
        startTimeRef.current = null
      }
      return
    }

    if (!shouldShowLoader) {
      // Stay on `/` until the destination route decides. Completing here
      // would hide the console overlay after `/` → org.
      // Marketing/auth pages must not mark the console initial load complete,
      // or homepage → console never shows the branded overlay.
      if (
        location.pathname !== '/' &&
        !skipStaticLoader &&
        routerStatus === 'idle' &&
        isFetching === 0 &&
        isMutating === 0
      ) {
        completeInitialLoad()
      }
      if (wasLoadingRef.current) {
        setIsLoading(false)
        wasLoadingRef.current = false
        startTimeRef.current = null
      }
      return
    }

    const currentHasActiveRequests = isFetching > 0 || isMutating > 0
    const isRouterLoading = routerStatus !== 'idle'

    const shouldHideLoader =
      !currentHasActiveRequests &&
      !isRouterLoading &&
      shellGatesReady &&
      wasLoadingRef.current

    const startMaxTimeout = () => {
      if (maxTimeoutRef.current) return
      maxTimeoutRef.current = setTimeout(() => {
        console.warn('Initial loader timeout - hiding loader after 20 seconds')
        setIsLoading(false)
        startTimeRef.current = null
        wasLoadingRef.current = false
        completeInitialLoad()
        maxTimeoutRef.current = null
      }, 20000)
    }

    // Arm on first console entry (including soft nav from marketing). useState
    // only initializes isLoading on mount, so a warm cache must still show the overlay.
    if (!wasLoadingRef.current) {
      clearHideTimeout()
      setIsLoading(true)
      startTimeRef.current = Date.now()
      wasLoadingRef.current = true
      startMaxTimeout()
    } else {
      startMaxTimeout()
    }

    if (shouldHideLoader) {
      if (!timeoutRef.current) {
        const minLoadTime = 800
        const elapsedTime = startTimeRef.current
          ? Date.now() - startTimeRef.current
          : 0
        const remainingTime = Math.max(0, minLoadTime - elapsedTime)

        timeoutRef.current = setTimeout(() => {
          timeoutRef.current = null
          setIsLoading(false)
          startTimeRef.current = null
          wasLoadingRef.current = false
          completeInitialLoad()
          clearMaxTimeout()
        }, remainingTime)
      }
    } else if (currentHasActiveRequests || isRouterLoading) {
      clearHideTimeout()
    }
  }, [
    shouldShowLoader,
    skipStaticLoader,
    routerStatus,
    location.pathname,
    isFetching,
    isMutating,
    shellGatesReady,
  ])

  return { isLoading, isAuthRoute, skipStaticLoader }
}
