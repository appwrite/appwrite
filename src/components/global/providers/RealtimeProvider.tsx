/**
 * RealtimeProvider
 *
 * Subscribes to the console realtime channel for the current project
 * and invalidates React Query cache when events are received so the UI updates.
 * When migration events arrive, notifies SessionMigrations so in-progress CSV
 * export/import can be shown (unless the user already dismissed that migration).
 * Unsubscribes on unmount or when projectId changes.
 *
 * Ensures only one subscription is active: awaits previous cleanup before
 * opening new connections so we never have duplicate WebSockets (e.g. from
 * Strict Mode or fast dependency changes).
 */

import { useCallback, useEffect, useRef, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { subscribeProjectRealtime } from '@/lib/realtime'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'

interface RealtimeProviderProps {
  children: ReactNode
  projectId: string
}

export function RealtimeProvider({
  children,
  projectId,
}: RealtimeProviderProps) {
  const queryClient = useQueryClient()
  const { addMigrationFromRealtime } = useSessionMigrations(projectId)
  const addMigrationRef = useRef(addMigrationFromRealtime)
  addMigrationRef.current = addMigrationFromRealtime

  const onMigrationEvent = useCallback(
    (payload: unknown) => {
      addMigrationRef.current?.(projectId, payload)
    },
    [projectId],
  )

  const cleanupRef = useRef<(() => Promise<void>) | null>(null)
  const cleanupPromiseRef = useRef<Promise<void> | null>(null)

  useEffect(() => {
    if (!projectId) return

    let cancelled = false

    async function run() {
      const previousCleanupPromise = cleanupPromiseRef.current
      cleanupPromiseRef.current = null
      if (previousCleanupPromise) {
        await previousCleanupPromise
      }
      if (cancelled) return

      const cleanup = await subscribeProjectRealtime(projectId, queryClient, {
        onMigrationEvent,
      })
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
  }, [projectId, queryClient, onMigrationEvent])

  return <>{children}</>
}
