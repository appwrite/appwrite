/**
 * RealtimeProvider
 *
 * Subscribes to console and project realtime channels for the current project
 * and invalidates React Query cache when events are received so the UI updates.
 * Unsubscribes on unmount or when projectId changes.
 *
 * Ensures only one subscription is active: awaits previous cleanup before
 * opening new connections so we never have duplicate WebSockets (e.g. from
 * Strict Mode or fast dependency changes).
 */

import { useEffect, useRef, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { subscribeProjectRealtime } from '@/lib/realtime'

interface RealtimeProviderProps {
  children: ReactNode
  projectId: string
}

export function RealtimeProvider({ children, projectId }: RealtimeProviderProps) {
  const queryClient = useQueryClient()
  const cleanupRef = useRef<(() => Promise<void>) | null>(null)
  const cleanupPromiseRef = useRef<Promise<void> | null>(null)

  useEffect(() => {
    if (!projectId) return

    let cancelled = false

    async function run() {
      // Wait for any previous subscription to fully close before opening new
      // ones so we never have duplicate WebSockets (e.g. from Strict Mode).
      const previousCleanupPromise = cleanupPromiseRef.current
      cleanupPromiseRef.current = null
      if (previousCleanupPromise) {
        await previousCleanupPromise
      }
      if (cancelled) return

      const cleanup = await subscribeProjectRealtime(projectId, queryClient)
      if (cancelled) {
        await cleanup()
        return
      }
      cleanupRef.current = cleanup
    }
    run()

    return () => {
      cancelled = true
      const cleanup = cleanupRef.current
      cleanupRef.current = null
      if (cleanup) {
        const promise = cleanup()
        cleanupPromiseRef.current = promise
      }
    }
  }, [projectId, queryClient])

  return <>{children}</>
}
