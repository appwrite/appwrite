import { useEffect, useState, useRef, useMemo } from 'react'
import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useRouter, useLocation } from '@tanstack/react-router'
import {
  loadDebugOverrides,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'

export function useInitialLoader() {
  // Router + location need to be resolved before computing initial loader state
  const router = useRouter()
  const location = useLocation()

  // Track all active queries and mutations (including Appwrite calls)
  const isFetching = useIsFetching()
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
      location.pathname === '/sign-out',
    [location.pathname],
  )

  const shouldShowLoader = useMemo(
    () =>
      !isAuthRoute &&
      !(location.pathname === '/') &&
      (location.pathname.startsWith('/protected') ||
        location.pathname.startsWith('/organizations') ||
        location.pathname.startsWith('/projects') ||
        location.pathname.startsWith('/console') ||
        location.pathname.startsWith('/account')),
    [location.pathname, isAuthRoute],
  )

  const [debugOverrides, setDebugOverrides] = useState(loadDebugOverrides)
  const effectiveShouldShowLoader = useMemo(
    () => shouldShowLoader && !debugOverrides.disableInitialLoader,
    [shouldShowLoader, debugOverrides.disableInitialLoader],
  )

  // Initialize loading state synchronously so the loader is visible on first paint
  const [isLoading, setIsLoading] = useState(() => effectiveShouldShowLoader)
  const timeoutRef = useRef<NodeJS.Timeout | null>(null)
  const maxTimeoutRef = useRef<NodeJS.Timeout | null>(null)
  const startTimeRef = useRef<number | null>(
    effectiveShouldShowLoader ? Date.now() : null,
  )
  const wasLoadingRef = useRef(effectiveShouldShowLoader)
  const hasCompletedInitialLoadRef = useRef(false)

  useEffect(() => {
    const unsubscribe = subscribeToDebugOverrides(setDebugOverrides)
    return () => {
      unsubscribe?.()
    }
  }, [])

  // Use refs to track previous values and prevent unnecessary re-renders
  const prevIsFetchingRef = useRef(isFetching)
  const prevIsMutatingRef = useRef(isMutating)
  const prevRouterStatusRef = useRef(router.state.status)
  const prevPathnameRef = useRef(location.pathname)

  useEffect(() => {
    // If initial load has already completed, never show loader again
    if (hasCompletedInitialLoadRef.current) {
      return
    }

    // Early return if we're on a route that shouldn't show loader
    // This prevents unnecessary processing on root/auth routes
    if (!effectiveShouldShowLoader) {
      // Don't show loader on public/auth routes
      // Auth pages and root route don't need loaders - mark as complete immediately
      if (isAuthRoute || location.pathname === '/') {
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

    // From here on, we only process if effectiveShouldShowLoader is true
    // This means we're on a route that should show the loader

    // Only process if something actually changed
    const routerStatusChanged =
      prevRouterStatusRef.current !== router.state.status
    const pathnameChanged = prevPathnameRef.current !== location.pathname
    const fetchingChanged = prevIsFetchingRef.current !== isFetching
    const mutatingChanged = prevIsMutatingRef.current !== isMutating

    // If nothing relevant changed, skip processing
    if (
      !routerStatusChanged &&
      !pathnameChanged &&
      !fetchingChanged &&
      !mutatingChanged
    ) {
      return
    }

    // Update refs
    prevIsFetchingRef.current = isFetching
    prevIsMutatingRef.current = isMutating
    prevRouterStatusRef.current = router.state.status
    prevPathnameRef.current = location.pathname

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
    // Route loaders use ensureQueryData, so when they finish isFetching goes to 0.
    // The router can stay "pending" on some nested routes; hiding on RQ idle fixes that
    // for all routes (support, change-plan, etc.) without path-specific checks.
    const shouldHideLoader = !currentHasActiveRequests && wasLoadingRef.current

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
    effectiveShouldShowLoader,
    router.state.status,
    location.pathname,
    isFetching,
    isMutating,
  ])

  return { isLoading }
}
