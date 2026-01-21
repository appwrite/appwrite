import { useEffect, useState, useRef } from 'react'
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
  const hasActiveRequests = isFetching > 0 || isMutating > 0

  // Determine if we should show loader
  // Show loader for protected routes and routes that typically need data loading
  const shouldShowLoader =
    location.pathname.startsWith('/protected') ||
    location.pathname.startsWith('/organizations') ||
    location.pathname.startsWith('/console') ||
    (location.pathname !== '/sign-in' &&
      location.pathname !== '/sign-up' &&
      location.pathname !== '/recovery' &&
      location.pathname !== '/')

  const [debugOverrides, setDebugOverrides] = useState(loadDebugOverrides)
  const effectiveShouldShowLoader =
    shouldShowLoader && !debugOverrides.disableInitialLoader

  // Initialize loading state synchronously so the loader is visible on first paint
  const [isLoading, setIsLoading] = useState(() => effectiveShouldShowLoader)
  const timeoutRef = useRef<NodeJS.Timeout | null>(null)
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

  useEffect(() => {
    // If initial load has already completed, never show loader again
    if (hasCompletedInitialLoadRef.current) {
      return
    }

    // Clear any existing timeout
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
      timeoutRef.current = null
    }

    if (effectiveShouldShowLoader) {
      // Check loading state
      // Router status can be: "idle" | "pending" | "loading"
      const isRouterLoading = router.state.status !== 'idle'
      const isCurrentlyLoading = isRouterLoading || hasActiveRequests

      if (isCurrentlyLoading && !wasLoadingRef.current) {
        // Started loading
        setIsLoading(true)
        startTimeRef.current = Date.now()
        wasLoadingRef.current = true
      } else if (!isCurrentlyLoading && wasLoadingRef.current) {
        // All requests completed - mark initial load as complete
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
        }, remainingTime)
      }
    } else {
      // Don't show loader on public/auth routes
      // But if we're on a public route and haven't completed initial load,
      // mark it as complete (user might have landed on sign-in page)
      if (!hasActiveRequests && router.state.status === 'idle') {
        hasCompletedInitialLoadRef.current = true
      }
      if (wasLoadingRef.current) {
        setIsLoading(false)
        wasLoadingRef.current = false
        startTimeRef.current = null
      }
    }

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }
    }
  }, [
    effectiveShouldShowLoader,
    router.state.status,
    hasActiveRequests,
    location.pathname,
  ])

  return { isLoading }
}
