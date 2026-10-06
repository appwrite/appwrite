import { useCallback, useEffect, useRef } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeVideoPlayerPrefsIntoPrefs,
  parseVideoPlayerPrefs,
  type UserPrefs,
  type VideoPlayerPrefs,
} from '@/lib/user-prefs-keys'

const PERSIST_DEBOUNCE_MS = 600

/**
 * Stream player settings stored in account prefs (`console.videos.player`).
 * Writes are debounced so dragging the volume slider sends one update.
 */
export function useVideoPlayerPrefs() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const stored = parseVideoPlayerPrefs(
    (account as Models.User | undefined)?.prefs as UserPrefs | undefined,
  )
  const storedRef = useRef(stored)
  storedRef.current = stored
  const pendingRef = useRef<VideoPlayerPrefs | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const mutation = useMutation({
    mutationFn: async (value: VideoPlayerPrefs) => {
      const current = (getConsoleAccountFromCache(queryClient) ?? account) as
        | Models.User
        | undefined
      if (!current) throw new Error('Account data not available')
      return await updateAccountPrefs(
        mergeVideoPlayerPrefsIntoPrefs(
          (current.prefs ?? {}) as UserPrefs,
          value,
        ),
        'video-player',
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })
  const mutateRef = useRef(mutation.mutate)
  mutateRef.current = mutation.mutate

  const flush = useCallback(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    const value = pendingRef.current
    if (!value) return
    pendingRef.current = null
    mutateRef.current(value)
  }, [])

  const update = useCallback(
    (patch: Partial<VideoPlayerPrefs>) => {
      if (!account) return
      const base = pendingRef.current ?? storedRef.current
      const next = { ...base, ...patch }
      if (
        (Object.keys(patch) as Array<keyof VideoPlayerPrefs>).every(
          (key) => base[key] === next[key],
        )
      ) {
        return
      }
      pendingRef.current = next
      if (timerRef.current !== null) clearTimeout(timerRef.current)
      timerRef.current = setTimeout(flush, PERSIST_DEBOUNCE_MS)
    },
    [account, flush],
  )

  /** Latest settings, including changes still waiting to be written. */
  const read = useCallback(() => pendingRef.current ?? storedRef.current, [])

  useEffect(() => {
    window.addEventListener('pagehide', flush)
    return () => {
      window.removeEventListener('pagehide', flush)
      flush()
    }
  }, [flush])

  return { read, update }
}
