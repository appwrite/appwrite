import { useCallback, useEffect, useRef, useState } from 'react'
import type {
  ApiExplorerClientAuthState,
  ApiExplorerProjectPlatform,
  ApiExplorerServerAuthState,
} from './types'
import {
  resolveApiExplorerAuthSnapshot,
  writeApiExplorerAuthToLocalStorage,
} from './auth-storage'

const API_EXPLORER_AUTH_PERSIST_DEBOUNCE_MS = 300

export function useApiExplorerAuthPersistence(
  projectId: string,
  fallbackPlatform: ApiExplorerProjectPlatform,
) {
  const [internalPlatform, setInternalPlatformState] =
    useState<ApiExplorerProjectPlatform>(() =>
      resolveApiExplorerAuthSnapshot(projectId, fallbackPlatform).platform,
    )
  const [clientAuth, setClientAuthState] = useState<ApiExplorerClientAuthState>(
    () => resolveApiExplorerAuthSnapshot(projectId, fallbackPlatform).clientAuth,
  )
  const [serverAuth, setServerAuthState] = useState<ApiExplorerServerAuthState>(
    () => resolveApiExplorerAuthSnapshot(projectId, fallbackPlatform).serverAuth,
  )

  const skipPersistRef = useRef(true)
  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const pendingSnapshotRef = useRef<ReturnType<
    typeof resolveApiExplorerAuthSnapshot
  > | null>(null)

  const hydrateFromStorage = useCallback(
    (nextProjectId: string, nextFallbackPlatform: ApiExplorerProjectPlatform) => {
      skipPersistRef.current = true
      const snapshot = resolveApiExplorerAuthSnapshot(
        nextProjectId,
        nextFallbackPlatform,
      )
      setInternalPlatformState(snapshot.platform)
      setClientAuthState(snapshot.clientAuth)
      setServerAuthState(snapshot.serverAuth)
      queueMicrotask(() => {
        skipPersistRef.current = false
      })
    },
    [],
  )

  useEffect(() => {
    hydrateFromStorage(projectId, fallbackPlatform)
  }, [fallbackPlatform, hydrateFromStorage, projectId])

  const schedulePersist = useCallback(
    (snapshot: ReturnType<typeof resolveApiExplorerAuthSnapshot>) => {
      if (skipPersistRef.current || !projectId) return

      pendingSnapshotRef.current = snapshot
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        const pending = pendingSnapshotRef.current
        if (!pending) return
        pendingSnapshotRef.current = null
        writeApiExplorerAuthToLocalStorage(projectId, pending)
      }, API_EXPLORER_AUTH_PERSIST_DEBOUNCE_MS)
    },
    [projectId],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  useEffect(() => {
    schedulePersist({
      platform: internalPlatform,
      clientAuth,
      serverAuth,
    })
  }, [clientAuth, internalPlatform, schedulePersist, serverAuth])

  const setInternalPlatform = useCallback(
    (platform: ApiExplorerProjectPlatform) => {
      setInternalPlatformState(platform)
    },
    [],
  )

  const setClientAuth = useCallback(
    (
      value:
        | ApiExplorerClientAuthState
        | ((previous: ApiExplorerClientAuthState) => ApiExplorerClientAuthState),
    ) => {
      setClientAuthState(value)
    },
    [],
  )

  const setServerAuth = useCallback(
    (
      value:
        | ApiExplorerServerAuthState
        | ((previous: ApiExplorerServerAuthState) => ApiExplorerServerAuthState),
    ) => {
      setServerAuthState(value)
    },
    [],
  )

  return {
    internalPlatform,
    setInternalPlatform,
    clientAuth,
    setClientAuth,
    serverAuth,
    setServerAuth,
  }
}
