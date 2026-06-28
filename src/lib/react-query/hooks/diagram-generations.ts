import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'
import {
  buildSavedDiagramGenerationsPrefs,
  DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY,
  MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH,
  parseSavedDiagramGenerations,
  removeSavedDiagramGeneration,
  type SavedDiagramGeneration,
  upsertSavedDiagramGeneration,
  USER_PREFS_KEY_DIAGRAM_GENERATIONS,
} from '@/lib/diagram-generator/generation-prefs'
import { DIAGRAM_STORAGE_KEY } from '@/lib/diagram-generator/constants'
import { normalizeDiagramDocument } from '@/lib/diagram-generator/storage'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'

function readLocalDiagramGenerations(): SavedDiagramGeneration[] {
  if (typeof window === 'undefined') return []
  try {
    const raw = window.localStorage.getItem(DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY)
    if (!raw) return []
    return parseSavedDiagramGenerations(raw)
  } catch {
    return []
  }
}

function writeLocalDiagramGenerations(list: SavedDiagramGeneration[]): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(
      DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY,
      JSON.stringify(list),
    )
  } catch {
    /* private mode */
  }
}

function readLegacySingleDiagramLocalStorage(): SavedDiagramGeneration | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(DIAGRAM_STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as { version?: number; document?: unknown }
    if (parsed?.version !== 1 || !parsed.document) return null
    const document = normalizeDiagramDocument(parsed.document as SavedDiagramGeneration['document'])
    const isEmpty =
      document.nodes.length === 0 &&
      document.edges.length === 0 &&
      document.title === 'Untitled diagram'
    if (isEmpty) return null
    return {
      id: crypto.randomUUID(),
      name: document.title.trim() || 'Imported diagram',
      updatedAt: Date.now(),
      document,
    }
  } catch {
    return null
  }
}

function clearLegacySingleDiagramLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.removeItem(DIAGRAM_STORAGE_KEY)
  } catch {
    /* private mode */
  }
}

export type DiagramGenerationsAccount = {
  prefs?: Record<string, unknown>
} | null | undefined

export function useDiagramGenerations(account: DiagramGenerationsAccount) {
  const queryClient = useQueryClient()
  const isAuthenticated = Boolean(account)
  const [localRevision, setLocalRevision] = useState(0)

  const generations = useMemo(() => {
    if (isAuthenticated) {
      return parseSavedDiagramGenerations(account?.prefs?.[USER_PREFS_KEY_DIAGRAM_GENERATIONS])
    }
    return readLocalDiagramGenerations()
  }, [account?.prefs, isAuthenticated, localRevision])

  const persistList = useCallback(
    async (next: SavedDiagramGeneration[]) => {
      if (isAuthenticated) {
        const currentAccount = getConsoleAccountFromCache(queryClient)
        if (!currentAccount) {
          throw new Error('Account not available')
        }
        const updatedAccount = await updateAccountPrefs({
          ...currentAccount.prefs,
          ...buildSavedDiagramGenerationsPrefs(next),
        })
        syncConsoleAccountAfterMutation(queryClient, { apiResult: updatedAccount })
        return
      }

      writeLocalDiagramGenerations(next)
      setLocalRevision((value) => value + 1)
    },
    [isAuthenticated, queryClient],
  )

  const saveMutation = useMutation({
    mutationFn: async (entry: SavedDiagramGeneration) => {
      const next = upsertSavedDiagramGeneration(generations, entry)
      await persistList(next)
      return next
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      const next = removeSavedDiagramGeneration(generations, id)
      await persistList(next)
      return next
    },
  })

  const renameMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const generation = generations.find((item) => item.id === id)
      if (!generation) {
        throw new Error('Diagram not found')
      }
      const trimmed = name.trim().slice(0, MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH)
      if (!trimmed) {
        throw new Error('Name is required')
      }
      const entry: SavedDiagramGeneration = {
        ...generation,
        name: trimmed,
        document: {
          ...generation.document,
          title: trimmed,
        },
      }
      const next = upsertSavedDiagramGeneration(generations, entry)
      await persistList(next)
      return next
    },
  })

  const migrateLegacyMutation = useMutation({
    mutationFn: async () => {
      if (generations.length > 0) return generations
      const legacy = readLegacySingleDiagramLocalStorage()
      if (!legacy) return generations
      const next = upsertSavedDiagramGeneration(generations, legacy)
      await persistList(next)
      clearLegacySingleDiagramLocalStorage()
      return next
    },
  })

  const saveGeneration = useCallback(
    (entry: SavedDiagramGeneration) => saveMutation.mutateAsync(entry),
    [saveMutation],
  )

  const deleteGeneration = useCallback(
    (id: string) => deleteMutation.mutateAsync(id),
    [deleteMutation],
  )

  const renameGeneration = useCallback(
    (id: string, name: string) => renameMutation.mutateAsync({ id, name }),
    [renameMutation],
  )

  const migrateLegacyIfNeeded = useCallback(
    () => migrateLegacyMutation.mutateAsync(),
    [migrateLegacyMutation],
  )

  return {
    generations,
    isAuthenticated,
    isSaving: saveMutation.isPending,
    isDeleting: deleteMutation.isPending,
    isRenaming: renameMutation.isPending,
    saveGeneration,
    deleteGeneration,
    renameGeneration,
    maxNameLength: MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH,
    migrateLegacyIfNeeded,
  }
}
