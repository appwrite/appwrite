'use client'

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/components/global/auth/RequireAuth'
import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import type { UserPrefs } from '@/lib/user-prefs-keys'
import type { ReferencePlatform, ReferenceVersion } from './constants'
import {
  mergeApiReferenceUiPrefs,
  mergeApiReferenceUiPrefsIntoAccountPrefs,
  parseApiReferenceUiPrefs,
  readApiReferenceUiPrefsFromLocalStorage,
  resolveApiReferenceUiPrefs,
  writeApiReferenceUiPrefsToLocalStorage,
  type ApiReferenceCardId,
  type ApiReferenceUiPrefs,
} from './api-reference-ui-prefs'

const API_REFERENCE_UI_PREFS_PERSIST_DEBOUNCE_MS = 300

type ApiReferenceUiPrefsContextValue = {
  prefs: ApiReferenceUiPrefs
  updatePrefs: (patch: Partial<ApiReferenceUiPrefs>) => void
  setCardOpen: (cardId: ApiReferenceCardId, open: boolean) => void
  setPlatformMode: (mode: ApiExplorerProjectPlatform) => void
  setClientPlatform: (platform: ReferencePlatform) => void
  setServerPlatform: (platform: ReferencePlatform) => void
  setVersion: (version: ReferenceVersion) => void
}

const ApiReferenceUiPrefsContext =
  createContext<ApiReferenceUiPrefsContextValue | null>(null)

export function ApiReferenceUiPrefsProvider({ children }: { children: ReactNode }) {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const [prefs, setPrefs] = useState<ApiReferenceUiPrefs>(() =>
    resolveApiReferenceUiPrefs(undefined),
  )
  const prefsRef = useRef(prefs)
  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const pendingPrefsRef = useRef<ApiReferenceUiPrefs | null>(null)
  const migratedFromLocalStorageRef = useRef(false)

  useEffect(() => {
    prefsRef.current = prefs
  }, [prefs])

  useEffect(() => {
    setPrefs(resolveApiReferenceUiPrefs(account?.prefs as UserPrefs | undefined))
  }, [account?.prefs])

  const updateMutation = useMutation({
    mutationFn: async (value: ApiReferenceUiPrefs) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeApiReferenceUiPrefsIntoAccountPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      const patch = mergeApiReferenceUiPrefsIntoAccountPrefs(
        (account?.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
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

  const schedulePersistToAccount = useCallback(
    (next: ApiReferenceUiPrefs) => {
      if (!account) return
      pendingPrefsRef.current = next
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        const pending = pendingPrefsRef.current
        if (!pending) return
        pendingPrefsRef.current = null
        updateMutation.mutate(pending)
      }, API_REFERENCE_UI_PREFS_PERSIST_DEBOUNCE_MS)
    },
    [account, updateMutation],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  useEffect(() => {
    if (!account || migratedFromLocalStorageRef.current) return

    const localPartial = readApiReferenceUiPrefsFromLocalStorage()
    if (!localPartial) {
      migratedFromLocalStorageRef.current = true
      return
    }

    const accountHasPrefs = parseApiReferenceUiPrefs(
      account.prefs as UserPrefs | undefined,
    )

    if (!accountHasPrefs) {
      const merged = mergeApiReferenceUiPrefs(
        resolveApiReferenceUiPrefs(undefined),
        localPartial,
      )
      writeApiReferenceUiPrefsToLocalStorage(merged)
      setPrefs(merged)
      schedulePersistToAccount(merged)
    }

    migratedFromLocalStorageRef.current = true
  }, [account, schedulePersistToAccount])

  const updatePrefs = useCallback(
    (patch: Partial<ApiReferenceUiPrefs>) => {
      setPrefs((current) => {
        const next = mergeApiReferenceUiPrefs(current, patch)
        if (
          next.version === current.version &&
          next.platformMode === current.platformMode &&
          next.clientPlatform === current.clientPlatform &&
          next.serverPlatform === current.serverPlatform &&
          next.cards.parameters === current.cards.parameters &&
          next.cards.responses === current.cards.responses
        ) {
          return current
        }
        prefsRef.current = next
        writeApiReferenceUiPrefsToLocalStorage(next)
        schedulePersistToAccount(next)
        return next
      })
    },
    [schedulePersistToAccount],
  )

  const setCardOpen = useCallback(
    (cardId: ApiReferenceCardId, open: boolean) => {
      updatePrefs({
        cards: {
          ...prefsRef.current.cards,
          [cardId]: open,
        },
      })
    },
    [updatePrefs],
  )

  const setPlatformMode = useCallback(
    (mode: ApiExplorerProjectPlatform) => {
      updatePrefs({ platformMode: mode })
    },
    [updatePrefs],
  )

  const setClientPlatform = useCallback(
    (platform: ReferencePlatform) => {
      updatePrefs({ clientPlatform: platform })
    },
    [updatePrefs],
  )

  const setServerPlatform = useCallback(
    (platform: ReferencePlatform) => {
      updatePrefs({ serverPlatform: platform })
    },
    [updatePrefs],
  )

  const setVersion = useCallback(
    (version: ReferenceVersion) => {
      updatePrefs({ version })
    },
    [updatePrefs],
  )

  const value = useMemo(
    () => ({
      prefs,
      updatePrefs,
      setCardOpen,
      setPlatformMode,
      setClientPlatform,
      setServerPlatform,
      setVersion,
    }),
    [
      prefs,
      updatePrefs,
      setCardOpen,
      setPlatformMode,
      setClientPlatform,
      setServerPlatform,
      setVersion,
    ],
  )

  return (
    <ApiReferenceUiPrefsContext.Provider value={value}>
      {children}
    </ApiReferenceUiPrefsContext.Provider>
  )
}

export function useApiReferenceUiPrefs(): ApiReferenceUiPrefsContextValue {
  const context = useContext(ApiReferenceUiPrefsContext)
  if (!context) {
    throw new Error(
      'useApiReferenceUiPrefs must be used within ApiReferenceUiPrefsProvider',
    )
  }
  return context
}

export function useOptionalApiReferenceUiPrefs():
  | ApiReferenceUiPrefsContextValue
  | null {
  return useContext(ApiReferenceUiPrefsContext)
}
