import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  createConfiguredQueryEntry,
  createConfiguredSubscription,
  mergeRealtimeDebuggerConfigIntoPrefs,
  parseRealtimeDebuggerConfig,
  type RealtimeConfiguredSubscription,
  type RealtimeDebuggerConfig,
} from '@/lib/realtime/debugger-prefs'
import type { SubscriptionQueryEntry } from '@/lib/realtime/subscription-queries'
import type { UserPrefs } from '@/lib/user-prefs-keys'

export function useRealtimeDebuggerConfig(
  projectId: string | null | undefined,
) {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountPrefs = (account as Models.User | undefined)?.prefs as
    | UserPrefs
    | undefined

  const config = parseRealtimeDebuggerConfig(accountPrefs, projectId)

  const persistMutation = useMutation({
    mutationFn: async (next: RealtimeDebuggerConfig) => {
      const currentAccount = account as Models.User | undefined
      if (!currentAccount || !projectId?.trim()) {
        throw new Error('Account data not available')
      }

      return await updateAccountPrefs(
        mergeRealtimeDebuggerConfigIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          projectId,
          next,
        ),
      )
    },
    onMutate: async (next) => {
      if (!projectId?.trim()) return

      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeRealtimeDebuggerConfigIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  projectId,
                  next,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const updateConfig = useCallback(
    (updater: (current: RealtimeDebuggerConfig) => RealtimeDebuggerConfig) => {
      if (!projectId?.trim()) return
      const next = updater(config)
      persistMutation.mutate(next)
    },
    [config, persistMutation, projectId],
  )

  const addSubscription = useCallback(
    (channel: string) => {
      const trimmed = channel.trim()
      if (!trimmed) return null

      const entry = createConfiguredSubscription(trimmed)
      updateConfig((current) => ({
        ...current,
        subscriptions: [...current.subscriptions, entry],
      }))
      return entry
    },
    [updateConfig],
  )

  const removeSubscription = useCallback(
    (entryId: string) => {
      updateConfig((current) => ({
        ...current,
        subscriptions: current.subscriptions.filter(
          (entry) => entry.id !== entryId,
        ),
      }))
    },
    [updateConfig],
  )

  const addSubscriptionQuery = useCallback(
    (subscriptionId: string, query: SubscriptionQueryEntry) => {
      updateConfig((current) => ({
        ...current,
        subscriptions: current.subscriptions.map((entry) =>
          entry.id === subscriptionId
            ? { ...entry, queries: [...entry.queries, query] }
            : entry,
        ),
      }))
    },
    [updateConfig],
  )

  const removeSubscriptionQuery = useCallback(
    (subscriptionId: string, queryId: string) => {
      updateConfig((current) => ({
        ...current,
        subscriptions: current.subscriptions.map((entry) =>
          entry.id === subscriptionId
            ? {
                ...entry,
                queries: entry.queries.filter((query) => query.id !== queryId),
              }
            : entry,
        ),
      }))
    },
    [updateConfig],
  )

  return {
    config,
    addSubscription,
    removeSubscription,
    addSubscriptionQuery,
    removeSubscriptionQuery,
    createQueryEntry: createConfiguredQueryEntry,
  }
}

export type { RealtimeConfiguredSubscription, RealtimeDebuggerConfig }
